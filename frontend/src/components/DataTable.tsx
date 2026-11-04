/**
 * src/client/src/components/DataTable.tsx
 * Tabella dati generica con intestazioni dinamiche, paginazione e gestione stati.
 */
import React from 'react';
import { ChevronLeft, ChevronRight, Inbox } from 'lucide-react';

export interface Column<T> {
  key: string;
  label: string;
  render?: (row: T) => React.ReactNode;
  width?: string | number;
}

interface DataTableProps<T> {
  columns: Column<T>[];
  data: T[];
  loading?: boolean;
  emptyMessage?: string;
  pagination?: {
    page: number;
    totalPages: number;
    totalCount?: number;
    onPageChange: (newPage: number) => void;
  };
}

export function DataTable<T extends Record<string, any>>({
  columns,
  data,
  loading = false,
  emptyMessage = 'Nessun dato presente',
  pagination
}: DataTableProps<T>) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      <div className="table-container">
        <table className="table">
          <thead>
            <tr>
              {columns.map((col) => (
                <th key={col.key} style={{ width: col.width }}>
                  {col.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={columns.length} style={{ textAlign: 'center', padding: '48px 16px' }}>
                  <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8, color: 'var(--text-muted)' }}>
                    <span>Caricamento dati in corso...</span>
                  </div>
                </td>
              </tr>
            ) : data.length === 0 ? (
              <tr>
                <td colSpan={columns.length} style={{ textAlign: 'center', padding: '48px 16px' }}>
                  <div
                    style={{
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'center',
                      gap: 8,
                      color: 'var(--text-muted)'
                    }}
                  >
                    <Inbox size={32} />
                    <span style={{ fontSize: '0.9rem' }}>{emptyMessage}</span>
                  </div>
                </td>
              </tr>
            ) : (
              data.map((row, idx) => (
                <tr key={row.id || idx}>
                  {columns.map((col) => (
                    <td key={col.key}>
                      {col.render ? col.render(row) : (row[col.key] !== undefined && row[col.key] !== null ? String(row[col.key]) : '-')}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {pagination && pagination.totalPages > 1 && (
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '4px 8px',
            fontSize: '0.85rem',
            color: 'var(--text-secondary)'
          }}
        >
          <div>
            {pagination.totalCount !== undefined ? `Totale elementi: ${pagination.totalCount}` : ''}
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <span>
              Pagina {pagination.page} di {pagination.totalPages}
            </span>
            <button
              className="btn btn-secondary btn-sm"
              disabled={pagination.page <= 1}
              onClick={() => pagination.onPageChange(pagination.page - 1)}
            >
              <ChevronLeft size={16} />
            </button>
            <button
              className="btn btn-secondary btn-sm"
              disabled={pagination.page >= pagination.totalPages}
              onClick={() => pagination.onPageChange(pagination.page + 1)}
            >
              <ChevronRight size={16} />
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
