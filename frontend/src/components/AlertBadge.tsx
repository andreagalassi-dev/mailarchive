/**
 * src/client/src/components/AlertBadge.tsx
 * Badge cromatico per la visualizzazione di stati, severità e ruoli.
 */
import React from 'react';

interface AlertBadgeProps {
  status?: string | null;
  label?: string;
}

export const AlertBadge: React.FC<AlertBadgeProps> = ({ status, label }) => {
  if (!status) return null;

  const normalized = status.toUpperCase();
  const displayLabel = label || status;

  let badgeClass = 'badge-secondary';

  switch (normalized) {
    case 'SUCCESS':
    case 'IDLE':
    case 'RESOLVED':
    case 'ACTIVE':
      badgeClass = 'badge-success';
      break;
    case 'WARNING':
    case 'PAUSED':
    case 'PARTIAL':
      badgeClass = 'badge-warning';
      break;
    case 'CRITICAL':
    case 'FAILED':
    case 'SPAM':
    case 'DEACTIVATED':
      badgeClass = 'badge-danger';
      break;
    case 'RUNNING':
    case 'INFO':
    case 'ADMIN':
      badgeClass = 'badge-info';
      break;
    default:
      badgeClass = 'badge-secondary';
  }

  return <span className={`badge ${badgeClass}`}>{displayLabel}</span>;
};
