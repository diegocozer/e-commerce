import AddIcon from '@mui/icons-material/AddOutlined';
import ArrowDownIcon from '@mui/icons-material/ArrowDownwardOutlined';
import ArrowUpIcon from '@mui/icons-material/ArrowUpwardOutlined';
import DeleteIcon from '@mui/icons-material/DeleteOutlineOutlined';
import EditIcon from '@mui/icons-material/EditOutlined';
import SubdirIcon from '@mui/icons-material/SubdirectoryArrowRightOutlined';
import { Box, Button, Card, IconButton, List, ListItem, ListItemText, Stack, Tooltip, Typography } from '@mui/material';
import { useState } from 'react';
import { flattenCategories } from '@/shared/api/lookups';
import type { AdminCategory } from '@/shared/api/types';
import { useCan } from '@/shared/auth';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ActiveChip, ConfirmDialog, EmptyState, ErrorState, LoadingBlock, notify, PageHeader } from '@/shared/ui';
import { errorMessage } from '@/shared/api/errors';
import { deleteCategory, useCategoryTree, useReorderCategories } from '../api';
import { CategoryDialog } from '../components/CategoryDialog';

interface NodeProps {
  node: AdminCategory;
  siblings: AdminCategory[];
  index: number;
  canManage: boolean;
  onEdit: (c: AdminCategory) => void;
  onAddChild: (c: AdminCategory) => void;
  onDelete: (c: AdminCategory) => void;
  onMove: (siblings: AdminCategory[], index: number, dir: -1 | 1) => void;
}

function Node({ node, siblings, index, canManage, onEdit, onAddChild, onDelete, onMove }: NodeProps) {
  return (
    <>
      <ListItem
        divider
        sx={{ pl: 4 + (node.depth - 1) * 8 }}
        secondaryAction={
          canManage && (
            <Stack direction="row">
              <IconButton aria-label={`Mover ${node.name} para cima`} disabled={index === 0} onClick={() => onMove(siblings, index, -1)}>
                <ArrowUpIcon fontSize="small" />
              </IconButton>
              <IconButton aria-label={`Mover ${node.name} para baixo`} disabled={index === siblings.length - 1} onClick={() => onMove(siblings, index, 1)}>
                <ArrowDownIcon fontSize="small" />
              </IconButton>
              {node.depth < 3 && (
                <Tooltip title="Nova subcategoria">
                  <IconButton aria-label={`Nova subcategoria em ${node.name}`} onClick={() => onAddChild(node)}>
                    <SubdirIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
              )}
              <IconButton aria-label={`Editar ${node.name}`} onClick={() => onEdit(node)}>
                <EditIcon fontSize="small" />
              </IconButton>
              <IconButton aria-label={`Excluir ${node.name}`} onClick={() => onDelete(node)}>
                <DeleteIcon fontSize="small" />
              </IconButton>
            </Stack>
          )
        }
      >
        <ListItemText
          primary={
            <Stack direction="row" spacing={2} sx={{ alignItems: 'center' }}>
              <span>{node.name}</span>
              <ActiveChip active={node.is_active} on="Ativa" off="Inativa" />
            </Stack>
          }
          secondary={`/${node.slug} · ${node.products_count} produtos`}
        />
      </ListItem>
      {node.children.map((c, i) => (
        <Node key={c.id} node={c} siblings={node.children} index={i} canManage={canManage} onEdit={onEdit} onAddChild={onAddChild} onDelete={onDelete} onMove={onMove} />
      ))}
    </>
  );
}

export default function CategoriesPage() {
  const tree = useCategoryTree();
  const canManage = useCan('products.manage');
  const [dialog, setDialog] = useState<{ category: AdminCategory | null; parentId: number | null } | null>(null);
  const reorder = useReorderCategories();
  const remove = useRemoveWithConfirm<AdminCategory>({ remove: (c) => deleteCategory(c.id), invalidate: ['admin', 'categories'], successMessage: 'Categoria excluída' });
  const parents = flattenCategories(tree.data);

  const onMove = (siblings: AdminCategory[], index: number, dir: -1 | 1) => {
    const ids = siblings.map((s) => s.id);
    [ids[index], ids[index + dir]] = [ids[index + dir], ids[index]];
    reorder.mutate({ parent_id: siblings[0].parent_id, ids }, { onError: (e) => notify.error(errorMessage(e)) });
  };

  return (
    <>
      <PageHeader
        title="Categorias"
        subtitle="Até 3 níveis. A ordem aqui é a ordem do menu da loja."
        actions={
          canManage && (
            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog({ category: null, parentId: null })}>
              Nova categoria
            </Button>
          )
        }
      />
      {tree.isPending && <LoadingBlock />}
      {tree.error && <ErrorState error={tree.error} onRetry={() => void tree.refetch()} />}
      {tree.data && (
        <Card>
          {tree.data.length === 0 ? (
            <EmptyState title="Nenhuma categoria cadastrada" />
          ) : (
            <List disablePadding aria-label="Árvore de categorias">
              {tree.data.map((c, i) => (
                <Node key={c.id} node={c} siblings={tree.data} index={i} canManage={canManage} onEdit={(x) => setDialog({ category: x, parentId: x.parent_id })} onAddChild={(x) => setDialog({ category: null, parentId: x.id })} onDelete={remove.ask} onMove={onMove} />
              ))}
            </List>
          )}
        </Card>
      )}
      {dialog && <CategoryDialog category={dialog.category} parentId={dialog.parentId} parents={parents} onClose={() => setDialog(null)} />}
      <ConfirmDialog
        open={!!remove.target}
        title={`Excluir a categoria "${remove.target?.name ?? ''}"?`}
        description="Categorias com subcategorias ativas ou que sejam principal de produtos ativos não podem ser excluídas."
        confirmLabel="Excluir categoria"
        destructive
        loading={remove.loading}
        onConfirm={remove.confirm}
        onClose={remove.cancel}
      />
      <Box sx={{ mt: 2 }}>
        <Typography variant="caption" color="text.secondary">
          Use as setas para reordenar entre irmãs.
        </Typography>
      </Box>
    </>
  );
}
