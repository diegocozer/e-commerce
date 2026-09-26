import { lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { createBrowserRouter, type RouteObject } from 'react-router';
import { GuestOnly, RequireCustomer } from '@/features/auth';
import { RouteError } from './components/RouteError';
import { CheckoutLayout } from './layouts/CheckoutLayout';
import { StoreLayout } from './layouts/StoreLayout';

// Code-splitting por rota (UX §8): cada página em um chunk.
const HomePage = lazy(() => import('@/features/catalog/pages/HomePage'));
const SearchPage = lazy(() => import('@/features/catalog/pages/SearchPage'));
const CategoryPage = lazy(() => import('@/features/catalog/pages/CategoryPage'));
const ProductPage = lazy(() => import('@/features/catalog/pages/ProductPage'));
const CartPage = lazy(() => import('@/features/cart/pages/CartPage'));
const CheckoutPage = lazy(() => import('@/features/checkout/pages/CheckoutPage'));
const OrderConfirmationPage = lazy(() => import('@/features/checkout/pages/OrderConfirmationPage'));
const LoginPage = lazy(() => import('@/features/auth/pages/LoginPage'));
const RegisterPage = lazy(() => import('@/features/auth/pages/RegisterPage'));
const ForgotPasswordPage = lazy(() => import('@/features/auth/pages/ForgotPasswordPage'));
const ResetPasswordPage = lazy(() => import('@/features/auth/pages/ResetPasswordPage'));
const VerifyEmailPage = lazy(() => import('@/features/auth/pages/VerifyEmailPage'));
const AccountLayout = lazy(() => import('@/features/account/pages/AccountLayout'));
const AccountOverviewPage = lazy(() => import('@/features/account/pages/AccountOverviewPage'));
const OrdersPage = lazy(() => import('@/features/account/pages/OrdersPage'));
const OrderDetailPage = lazy(() => import('@/features/account/pages/OrderDetailPage'));
const AddressesPage = lazy(() => import('@/features/account/pages/AddressesPage'));
const ProfilePage = lazy(() => import('@/features/account/pages/ProfilePage'));
const PasswordPage = lazy(() => import('@/features/account/pages/PasswordPage'));
const InstitutionalPage = lazy(() => import('@/features/institutional/pages/InstitutionalPage'));
const NotFoundRoute = lazy(() => import('@/features/errors/pages/NotFoundPage'));

const el = (C: LazyExoticComponent<ComponentType>) => <C />;
const guest = (C: LazyExoticComponent<ComponentType>) => <GuestOnly><C /></GuestOnly>;
const customer = (C: LazyExoticComponent<ComponentType>) => <RequireCustomer><C /></RequireCustomer>;

/**
 * Ordem (UX §3.1 / ADR-015/026a): rotas estáticas (slugs reservados) primeiro; depois
 * /:categorySlug/:productSlug e /:categorySlug; por fim 404. O React Router ranqueia
 * segmentos estáticos acima de dinâmicos; CategoryPage também rejeita slugs reservados.
 */
export const routes: RouteObject[] = [
  {
    element: <StoreLayout />,
    errorElement: <RouteError />,
    children: [
      { index: true, element: el(HomePage) },
      { path: 'busca', element: el(SearchPage) },
      { path: 'carrinho', element: el(CartPage) },
      { path: 'entrar', element: guest(LoginPage) },
      { path: 'cadastro', element: guest(RegisterPage) },
      { path: 'recuperar-senha', element: guest(ForgotPasswordPage) },
      { path: 'redefinir-senha', element: el(ResetPasswordPage) },
      { path: 'verificar-email', element: el(VerifyEmailPage) },
      { path: 'institucional/:slug', element: el(InstitutionalPage) },
      {
        path: 'conta',
        element: customer(AccountLayout),
        children: [
          { index: true, element: el(AccountOverviewPage) },
          { path: 'pedidos', element: el(OrdersPage) },
          { path: 'pedidos/:uuid', element: el(OrderDetailPage) },
          { path: 'enderecos', element: el(AddressesPage) },
          { path: 'dados', element: el(ProfilePage) },
          { path: 'senha', element: el(PasswordPage) },
        ],
      },
      // reservados sem página própria na loja → 404 explícito (não viram categoria)
      { path: 'admin/*', element: el(NotFoundRoute) },
      { path: 'api/*', element: el(NotFoundRoute) },
      { path: 'sanctum/*', element: el(NotFoundRoute) },
      { path: 'institucional', element: el(NotFoundRoute) },
      { path: ':categorySlug/:productSlug', element: el(ProductPage) },
      { path: ':categorySlug', element: el(CategoryPage) },
      { path: '*', element: el(NotFoundRoute) },
    ],
  },
  {
    element: <CheckoutLayout />,
    errorElement: <RouteError />,
    children: [
      { path: 'checkout', element: customer(CheckoutPage) },
      { path: 'checkout/pedido/:uuid', element: customer(OrderConfirmationPage) },
    ],
  },
];

export function createAppRouter() {
  return createBrowserRouter(routes);
}
