/**
 * src/client/src/components/SanitizedHtmlViewer.tsx
 * Visualizzatore sandboxed per l'HTML delle email.
 * Esegue il rendering all'interno di un <iframe> con restrizioni rigide (sandbox="allow-same-origin allow-popups"),
 * bloccando in modo invalicabile qualsiasi esecuzione di script JS a livello di browser.
 */
import React, { useMemo } from 'react';

interface SanitizedHtmlViewerProps {
  htmlContent: string;
  theme?: 'dark' | 'light';
  minHeight?: number | string;
}

export const SanitizedHtmlViewer: React.FC<SanitizedHtmlViewerProps> = ({
  htmlContent,
  theme = 'dark',
  minHeight = 400
}) => {
  const fullHtml = useMemo(() => {
    // Stili isolati per il documento interno dell'iframe
    const isDark = theme === 'dark';
    const bgColor = isDark ? '#111827' : '#ffffff';
    const textColor = isDark ? '#f3f4f6' : '#1f2937';
    const linkColor = isDark ? '#60a5fa' : '#2563eb';
    const borderColor = isDark ? '#374151' : '#e5e7eb';

    return `
      <!DOCTYPE html>
      <html>
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <style>
            body {
              font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
              font-size: 14px;
              line-height: 1.6;
              color: ${textColor};
              background-color: ${bgColor};
              margin: 16px;
              word-break: break-word;
            }
            a {
              color: ${linkColor};
              text-decoration: underline;
            }
            img {
              max-width: 100%;
              height: auto;
            }
            table {
              border-collapse: collapse;
              max-width: 100%;
            }
            th, td {
              border: 1px solid ${borderColor};
              padding: 8px;
            }
            blockquote {
              margin: 10px 0;
              padding-left: 12px;
              border-left: 3px solid ${borderColor};
              color: #9ca3af;
            }
          </style>
        </head>
        <body>
          ${htmlContent || '<p style="color: #9ca3af; font-style: italic;">Nessun contenuto visualizzabile.</p>'}
        </body>
      </html>
    `;
  }, [htmlContent, theme]);

  return (
    <div
      style={{
        width: '100%',
        border: '1px solid var(--border-color)',
        borderRadius: 'var(--radius-sm)',
        overflow: 'hidden',
        backgroundColor: theme === 'dark' ? '#111827' : '#ffffff'
      }}
    >
      <iframe
        title="Email Body Viewer"
        srcDoc={fullHtml}
        sandbox="allow-popups"
        style={{
          width: '100%',
          minHeight,
          border: 'none',
          display: 'block'
        }}
      />
    </div>
  );
};
