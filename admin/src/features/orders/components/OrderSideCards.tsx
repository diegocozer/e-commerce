import { Alert, Button, Card, CardContent, Chip, Link, List, ListItem, ListItemText, Stack, TextField, Typography } from '@mui/material';
import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { errorMessage } from '@/shared/api/errors';
import type { AdminOrder } from '@/shared/api/types';
import { Can } from '@/shared/auth';
import { formatDateTime } from '@/shared/formatters/date';
import { formatCEP, formatDocument, formatPhone } from '@/shared/formatters/document';
import { ACTOR_TYPE, ORDER_STATUS, PAYMENT_RECORD_STATUS } from '@/shared/formatters/labels';
import { formatBRL } from '@/shared/formatters/money';
import { KeyValue, notify } from '@/shared/ui';
import { usePatchOrder, useReconcilePayment, useRevealOrderDocument } from '../api';

export function CustomerCard({ order }: { order: AdminOrder }) {
  const c = order.customer;
  const reveal = useRevealOrderDocument(order.id);
  return (
    <Card>
      <CardContent>
        <Typography variant="h3" component="h2" sx={{ mb: 3 }}>
          Cliente
        </Typography>
        <KeyValue
          items={[
            { label: 'Nome', value: <>{c.company_name ? `${c.company_name} (PJ) · ${c.name}` : `${c.name} (PF)`}</> },
            {
              label: c.type === 'company' ? 'CNPJ' : 'CPF',
              value: (
                <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                  <span className="num">{reveal.data ? formatDocument(reveal.data.customer_document) : c.document_masked}</span>
                  {!reveal.data && (
                    <Can perm="customers.view_sensitive">
                      <Button size="small" onClick={() => reveal.mutate(undefined, { onError: (e) => notify.error(errorMessage(e)) })} disabled={reveal.isPending}>
                        Mostrar
                      </Button>
                    </Can>
                  )}
                </Stack>
              ),
            },
            ...(c.state_registration ? [{ label: 'IE', value: c.state_registration }] : []),
            { label: 'Telefone', value: formatPhone(c.phone) },
            { label: 'E-mail', value: <Link href={`mailto:${c.email}`}>{c.email}</Link> },
          ]}
        />
        <Can perm="customers.view">
          <Button component={RouterLink} to={`/clientes/${c.id}`} size="small" sx={{ mt: 2 }}>
            Ver cliente
          </Button>
        </Can>
      </CardContent>
    </Card>
  );
}

export function ShippingCard({ order }: { order: AdminOrder }) {
  const s = order.shipping;
  const reveal = useRevealOrderDocument(order.id);
  return (
    <Card>
      <CardContent>
        <Typography variant="h3" component="h2" sx={{ mb: 3 }}>
          Entrega
        </Typography>
        <KeyValue
          items={[
            { label: 'Método', value: `${s.method_name}${s.delivery_label ? ` · ${s.delivery_label}` : ''}` },
            ...(s.estimated_delivery_date ? [{ label: 'Previsão', value: s.estimated_delivery_date.split('-').reverse().join('/') }] : []),
            ...(s.address
              ? [
                  { label: 'Endereço', value: s.address.formatted },
                  { label: 'CEP', value: formatCEP(s.address.postal_code) },
                  { label: 'Destinatário', value: `${s.address.recipient_name}${s.address.phone ? ` · ${formatPhone(s.address.phone)}` : ''}` },
                  ...(s.address.reference ? [{ label: 'Referência', value: s.address.reference }] : []),
                ]
              : []),
            ...(s.pickup_address
              ? [{ label: 'Retirada em', value: `${s.pickup_address.street}, ${s.pickup_address.number} – ${s.pickup_address.district} – ${s.pickup_address.city}/${s.pickup_address.state}` }]
              : []),
            {
              label: 'Rastreio',
              value: s.tracking_code ? (
                s.tracking_url ? (
                  <Link href={s.tracking_url} target="_blank" rel="noopener noreferrer">
                    {s.tracking_code}
                  </Link>
                ) : (
                  s.tracking_code
                )
              ) : (
                '—'
              ),
            },
            ...(s.picked_up_by_name
              ? [
                  {
                    label: 'Retirado por',
                    value: (
                      <>
                        {s.picked_up_by_name} · {reveal.data?.picked_up_by_document ?? s.picked_up_by_document_masked}{' '}
                        {!reveal.data && (
                          <Can perm="customers.view_sensitive">
                            <Button size="small" onClick={() => reveal.mutate()}>
                              Mostrar
                            </Button>
                          </Can>
                        )}
                      </>
                    ),
                  },
                  { label: 'Retirado em', value: formatDateTime(s.picked_up_at) },
                ]
              : []),
          ]}
        />
      </CardContent>
    </Card>
  );
}

export function PaymentCard({ order }: { order: AdminOrder }) {
  const reconcile = useReconcilePayment(order.id);
  return (
    <Card>
      <CardContent>
        <Stack direction="row" sx={{ justifyContent: 'space-between', mb: 3 }}>
          <Typography variant="h3" component="h2">
            Pagamento
          </Typography>
          {order.payments.length > 0 && (
            <Can perm="payments.reconcile">
              <Button
                size="small"
                disabled={reconcile.isPending}
                onClick={() =>
                  reconcile.mutate(undefined, {
                    onSuccess: () => notify.success('Pagamento reconsultado no gateway'),
                    onError: (e) => notify.error(errorMessage(e)),
                  })
                }
              >
                Reconsultar gateway
              </Button>
            </Can>
          )}
        </Stack>
        {order.flags.amount_mismatch && (
          <Alert severity="error" sx={{ mb: 2 }}>
            Valor pago diverge do total do pedido. Verifique com o financeiro.
          </Alert>
        )}
        {order.payments.length === 0 && <Typography variant="body2">Nenhum pagamento gerado.</Typography>}
        {order.payments.map((p) => (
          <Stack key={p.id} spacing={1} sx={{ mb: 3 }}>
            <Typography variant="body2">
              <strong>{p.method.toUpperCase()}</strong> · {p.provider === 'mercadopago' ? 'Mercado Pago' : 'Sandbox'}
              {p.external_id ? ` · id ${p.external_id}` : ''} · <Chip size="small" label={PAYMENT_RECORD_STATUS[p.status]} />
            </Typography>
            <Typography variant="body2" className="num">
              Valor {formatBRL(p.amount_cents)}
              {p.paid_at && ` · aprovado ${formatDateTime(p.paid_at)}`}
              {p.refunded_cents > 0 && ` · estornado ${formatBRL(p.refunded_cents)}`}
            </Typography>
            {p.failure_reason && <Alert severity="warning">{p.failure_reason}</Alert>}
            {p.refund && (
              <Alert severity={p.refund.status === 'failed' ? 'error' : p.refund.status === 'succeeded' ? 'success' : 'info'}>
                Estorno de {formatBRL(p.refund.amount_cents)}{' '}
                {p.refund.status === 'pending'
                  ? 'solicitado — aguardando confirmação do gateway.'
                  : p.refund.status === 'succeeded'
                    ? 'concluído.'
                    : 'falhou. O financeiro foi alertado; realize o estorno manualmente no gateway.'}{' '}
                ({formatDateTime(p.refund.requested_at)})
              </Alert>
            )}
            {p.transactions && p.transactions.length > 0 && (
              <List dense disablePadding aria-label="Transações">
                {p.transactions.map((t) => (
                  <ListItem key={t.id} disableGutters>
                    <ListItemText
                      primary={`${formatDateTime(t.created_at)} · ${t.type} · ${t.status_before ? `${PAYMENT_RECORD_STATUS[t.status_before]} → ` : ''}${PAYMENT_RECORD_STATUS[t.status_after]}`}
                      secondary={`${formatBRL(t.amount_cents)}${t.admin_user ? ` · ${t.admin_user.name}` : ''}`}
                    />
                  </ListItem>
                ))}
              </List>
            )}
          </Stack>
        ))}
      </CardContent>
    </Card>
  );
}

export function HistoryCard({ order }: { order: AdminOrder }) {
  return (
    <Card>
      <CardContent>
        <Typography variant="h3" component="h2" sx={{ mb: 3 }}>
          Histórico
        </Typography>
        <List dense disablePadding aria-label="Linha do tempo do pedido">
          {order.status_history.map((h) => (
            <ListItem key={h.id} disableGutters alignItems="flex-start">
              <ListItemText
                primary={
                  <>
                    <span className="num">{formatDateTime(h.created_at)}</span> · <strong>{ORDER_STATUS[h.to_status].label}</strong> —{' '}
                    {h.actor.name ? `${h.actor.name} (${ACTOR_TYPE[h.actor.type].toLowerCase()})` : ACTOR_TYPE[h.actor.type].toLowerCase()}
                  </>
                }
                secondary={h.note}
              />
            </ListItem>
          ))}
        </List>
      </CardContent>
    </Card>
  );
}

export function NotesCard({ order }: { order: AdminOrder }) {
  const [text, setText] = useState(order.internal_notes ?? '');
  const patch = usePatchOrder(order.id);
  return (
    <Card>
      <CardContent>
        <Typography variant="h3" component="h2" sx={{ mb: 3 }}>
          Observações
        </Typography>
        <Typography variant="body2" sx={{ mb: 3 }}>
          <strong>Do cliente:</strong> {order.notes ? `"${order.notes}"` : '—'}
        </Typography>
        <Can perm="orders.notes" fallback={<Typography variant="body2"><strong>Internas:</strong> {order.internal_notes ?? '—'}</Typography>}>
          <TextField label="Notas internas" multiline minRows={3} value={text} onChange={(e) => setText(e.target.value)} slotProps={{ htmlInput: { maxLength: 5000 } }} />
          <Button
            size="small"
            sx={{ mt: 2 }}
            disabled={patch.isPending || text === (order.internal_notes ?? '')}
            onClick={() =>
              patch.mutate({ internal_notes: text || null }, { onSuccess: () => notify.success('Notas salvas'), onError: (e) => notify.error(errorMessage(e)) })
            }
          >
            Salvar notas
          </Button>
        </Can>
      </CardContent>
    </Card>
  );
}
