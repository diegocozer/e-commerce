// ADR-015 + ADR-026a: slugs reservados nunca resolvem como categoria.
export const RESERVED_SLUGS = [
  'busca', 'carrinho', 'checkout', 'conta', 'entrar', 'cadastro', 'recuperar-senha', 'redefinir-senha',
  'institucional', 'admin', 'api', 'sanctum', 'sitemap.xml', 'robots.txt',
  // rota da SPA para o link de verificação de e-mail (API §3.C) — ver relatório
  'verificar-email',
] as const;

export function isReservedSlug(slug: string): boolean {
  return (RESERVED_SLUGS as readonly string[]).includes(slug.toLowerCase());
}
