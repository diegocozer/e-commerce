import DOMPurify from 'dompurify';

// ADR-024: allowlist idêntica à do backend (p, br, strong, em, ul, ol, li, h2, h3,
// a[href], table básica). Sanitiza de novo no front antes de renderizar.
const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'h2', 'h3', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];
const ALLOWED_ATTR = ['href'];

let hookInstalled = false;

export function sanitizeHtml(html: string): string {
  if (!hookInstalled && typeof DOMPurify.addHook === 'function') {
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
      if (node.tagName === 'A') {
        node.setAttribute('rel', 'noopener noreferrer nofollow');
        const href = node.getAttribute('href') ?? '';
        if (/^https?:\/\//i.test(href)) node.setAttribute('target', '_blank');
      }
    });
    hookInstalled = true;
  }
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|\/)/i,
  });
}
