/** Regras compartilhadas de validação (espelho da API). */
export const RESERVED_SLUGS = ['busca', 'carrinho', 'checkout', 'conta', 'entrar', 'cadastro', 'recuperar-senha', 'redefinir-senha', 'institucional', 'admin', 'api', 'sanctum', 'sitemap.xml', 'robots.txt'];
export const SLUG_RE = /^[a-z0-9]+(-[a-z0-9]+)*$/;
export const CEP_RE = /^\d{5}-?\d{3}$/;
export const HTTPS_RE = /^https:\/\/\S+$/;
