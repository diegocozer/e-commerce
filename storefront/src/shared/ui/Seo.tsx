import { Helmet } from 'react-helmet-async';
import type { Seo as SeoData } from '../api/types';
import { env } from '../lib/env';

interface Props {
  title: string;
  description?: string | null;
  canonical?: string | null;
  robots?: string;
  ogImage?: string | null;
  jsonLd?: Record<string, unknown>[];
}

/** Escapa `<` para não fechar o <script> (mesma proteção do shell do backend). */
function safeJson(value: unknown): string {
  return JSON.stringify(value).replace(/</g, '\\u003c');
}

export function Seo({ title, description, canonical, robots, ogImage, jsonLd }: Props) {
  const fullTitle = title.includes(env.storeName) ? title : `${title} | ${env.storeName}`;
  return (
    <Helmet prioritizeSeoTags>
      <title>{fullTitle}</title>
      {description ? <meta name="description" content={description} /> : null}
      {canonical ? <link rel="canonical" href={canonical} /> : null}
      {robots ? <meta name="robots" content={robots} /> : null}
      <meta property="og:title" content={fullTitle} />
      {description ? <meta property="og:description" content={description} /> : null}
      {ogImage ? <meta property="og:image" content={ogImage} /> : null}
      {(jsonLd ?? []).map((ld, i) => (
        <script key={i} type="application/ld+json">
          {safeJson(ld)}
        </script>
      ))}
    </Helmet>
  );
}

export function SeoFromApi({ seo }: { seo: SeoData }) {
  return (
    <Seo
      title={seo.title}
      description={seo.description}
      canonical={seo.canonical_url}
      robots={seo.robots}
      ogImage={seo.og_image_url}
      jsonLd={seo.json_ld}
    />
  );
}

export function NoIndex({ title }: { title: string }) {
  return <Seo title={title} robots="noindex,follow" />;
}
