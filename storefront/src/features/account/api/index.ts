import { getData, http, patchData, postData, send } from '@/shared/api/client';
import type {
  Address,
  AddressInput,
  Customer,
  OrderDetail,
  OrderPayment,
  OrderStatus,
  OrderStatusPoll,
  OrderSummary,
  Paginated,
  ReorderReport,
  ReorderSuggestion,
} from '@/shared/api/types';
import { uuidv4 } from '@/shared/lib/uuid';

export const accountKeys = {
  addresses: ['me', 'addresses'] as const,
  orders: (params: { page: number; status?: string | null }) => ['me', 'orders', 'list', params] as const,
  order: (uuid: string) => ['me', 'orders', 'detail', uuid] as const,
  orderStatus: (uuid: string) => ['me', 'orders', 'status', uuid] as const,
  reorderSuggestions: ['me', 'reorder-suggestions'] as const,
};

export const fetchAddresses = () => getData<Address[]>('/me/addresses');
export const createAddress = (input: AddressInput) => postData<Address>('/me/addresses', input);
export const updateAddress = (uuid: string, input: Partial<AddressInput>) => patchData<Address>(`/me/addresses/${uuid}`, input);
export const deleteAddress = (uuid: string) => send('delete', `/me/addresses/${uuid}`);
export const setDefaultAddress = (uuid: string) => postData<Address>(`/me/addresses/${uuid}/default`);

export async function fetchOrders(params: { page: number; status?: OrderStatus[] | null; per_page?: number }): Promise<Paginated<OrderSummary>> {
  const res = await http.get<Paginated<OrderSummary>>('/me/orders', {
    params: { page: params.page, per_page: params.per_page ?? 10, status: params.status?.length ? params.status.join(',') : undefined },
  });
  return res.data;
}
export const fetchOrder = (uuid: string) => getData<OrderDetail>(`/me/orders/${uuid}`);
export const fetchOrderStatus = (uuid: string) => getData<OrderStatusPoll>(`/me/orders/${uuid}/status`);
export const cancelOrder = (uuid: string, reason?: string) => postData<OrderDetail>(`/me/orders/${uuid}/cancel`, reason ? { reason } : {});
export const requestCancellation = (uuid: string, reason: string) => postData<OrderDetail>(`/me/orders/${uuid}/cancellation-request`, { reason });
export const reorder = (uuid: string) => postData<ReorderReport>(`/me/orders/${uuid}/reorder`);
export const retryPayment = (uuid: string) =>
  postData<OrderPayment>(`/me/orders/${uuid}/payment`, { payment_method: 'pix' }, { headers: { 'Idempotency-Key': uuidv4() } });
export const fetchReorderSuggestions = (limit = 6) => getData<ReorderSuggestion[]>('/me/reorder-suggestions', { params: { limit } });
/** Só em dev/sandbox (API §3.F). */
export const devApprovePayment = (uuid: string) => postData<{ status: string }>(`/dev/payments/${uuid}/approve`);

export const updateProfile = (input: { name?: string; phone?: string; marketing_opt_in?: boolean; cpf?: string }) => patchData<Customer>('/me', input);
export const updateCompany = (input: { legal_name: string; trade_name: string | null; state_registration: string | null; state_registration_exempt: boolean }) =>
  patchData<Customer>('/me/company', input);
export const changePassword = (input: { current_password: string; password: string; password_confirmation: string }) => send('put', '/me/password', input);
export const acceptTerms = (terms_version: string) => postData<Customer>('/me/terms-acceptance', { terms_version, accept_terms: true });
