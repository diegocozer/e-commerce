import { http, HttpResponse } from 'msw';
import { db } from '../db';
import { B, invalid, ok } from '../util';

export const authHandlers = [
  http.get('*/sanctum/csrf-cookie', () => {
    if (typeof document !== 'undefined') document.cookie = 'XSRF-TOKEN=mock-xsrf-token; path=/';
    return new HttpResponse(null, { status: 204 });
  }),
  http.post(`${B}/admin/auth/login`, async ({ request }) => {
    const { email, password } = (await request.json()) as { email?: string; password?: string };
    if (!email) return invalid({ email: ['Informe o e-mail.'] });
    if (!password || password === 'errada') return invalid({ email: ['E-mail ou senha inválidos.'] });
    if (email === 'bloqueado@comunika.test') return HttpResponse.json({ message: 'Conta desativada.', code: 'account_disabled' }, { status: 403 });
    db.loggedIn = true;
    return ok(db.me);
  }),
  http.post(`${B}/admin/auth/logout`, () => {
    db.loggedIn = false;
    return new HttpResponse(null, { status: 204 });
  }),
  http.post(`${B}/admin/auth/forgot-password`, () => ok({ message: 'Se o e-mail estiver cadastrado, você receberá um link em instantes.' })),
  http.post(`${B}/admin/auth/reset-password`, () => ok({ message: 'Senha redefinida. Entre com a nova senha.' })),
  http.get(`${B}/admin/me`, () => (db.loggedIn ? ok(db.me) : HttpResponse.json({ message: 'Não autenticado.', code: 'unauthenticated' }, { status: 401 }))),
  http.put(`${B}/admin/me/password`, async ({ request }) => {
    const b = (await request.json()) as { current_password: string };
    if (b.current_password === 'errada') return invalid({ current_password: ['A senha atual está incorreta.'] });
    return new HttpResponse(null, { status: 204 });
  }),
  http.get(`${B}/admin/notifications`, () => HttpResponse.json({ data: [], links: {}, meta: { current_page: 1, from: null, last_page: 1, path: '', per_page: 25, to: null, total: 0, links: [] } })),
];
