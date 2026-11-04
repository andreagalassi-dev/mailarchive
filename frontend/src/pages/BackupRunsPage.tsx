/**
 * src/client/src/pages/BackupRunsPage.tsx
 * Storico dettagliato delle esecuzioni di sincronizzazione e backup (ADMIN e VIEWER).
 */
import React, { useEffect, useState, useCallback } from 'react';
import { History, Eye, RefreshCw, Terminal } from 'lucide-react';
import { api } from '../services/api';
import { useToast } from '../components/Toast';
import { DataTable, Column } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { AlertBadge } from '../components/AlertBadge';

export const BackupRunsPage: React.FC = () => {
  const { showToast } = useToast();
  const [runs, setRuns] = useState<any[]>([]);
  const [mailboxes, setMailboxes] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);

  // Filtri
  const [statusFilter, setStatusFilter] = useState('');
  const [mailboxFilter, setMailboxFilter] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalCount, setTotalCount] = useState(0);

  // Modal Dettaglio Run & Log
  const [selectedRun, setSelectedRun] = useState<any | null>(null);

  useEffect(() => {
    api.get('/api/v1/mailboxes')
      .then((res) => setMailboxes(res.data || []))
      .catch(() => {});
  }, []);

  const loadRuns = useCallback(async (targetPage: number) => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      params.set('page', String(targetPage));
      params.set('limit', '20');
      if (statusFilter) params.set('status', statusFilter);
      if (mailboxFilter) params.set('mailboxId', mailboxFilter);

      const res = await api.get(`/api/v1/backup-runs?${params.toString()}`);
      setRuns(res.data || []);
      setPage(res.pagination?.page || 1);
      setTotalPages(res.pagination?.totalPages || 1);
      setTotalCount(res.pagination?.totalCount || 0);
    } catch (err: any) {
      showToast(err.message || 'Errore nel caricamento delle esecuzioni di backup', 'error');
    } finally {
      setLoading(false);
    }
  }, [statusFilter, mailboxFilter, showToast]);

  useEffect(() => {
    loadRuns(page);
  }, [page, loadRuns]);

  const columns: Column<any>[] = [
    {
      key: 'startedAt',
      label: 'Avvio Esecuzione',
      render: (r) => (
        <div>
          <span style={{ fontSize: '0.85rem', fontWeight: 500 }}>{new Date(r.startedAt).toLocaleString()}</span>
          {r.completedAt && (
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
              Fine: {new Date(r.completedAt).toLocaleTimeString()}
            </div>
          )}
        </div>
      ),
      width: 190
    },
    {
      key: 'mailbox',
      label: 'Casella',
      render: (r) => (
        <div>
          <div style={{ fontWeight: 600 }}>{r.mailbox?.name || r.mailbox?.emailAddress}</div>
          <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>{r.mailbox?.emailAddress}</div>
        </div>
      ),
      width: 220
    },
    {
      key: 'status',
      label: 'Esito',
      render: (r) => <AlertBadge status={r.status} />,
      width: 110
    },
    {
      key: 'emailsImported',
      label: 'Importate',
      render: (r) => <span style={{ fontWeight: 600, color: 'var(--success)' }}>+{r.emailsImported}</span>,
      width: 90
    },
    {
      key: 'emailsSkipped',
      label: 'Saltate (Dup)',
      render: (r) => <span style={{ color: 'var(--text-muted)' }}>{r.emailsSkipped}</span>,
      width: 100
    },
    {
      key: 'totalSizeBytes',
      label: 'Dati Archiviati',
      render: (r) => (
        <span style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
          {(r.totalSizeBytes / (1024 * 1024)).toFixed(2)} MB
        </span>
      ),
      width: 120
    },
    {
      key: 'actions',
      label: 'Dettagli',
      render: (r) => (
        <button
          onClick={() => setSelectedRun(r)}
          className="btn btn-secondary btn-sm"
          title="Mostra log e dettagli"
        >
          <Eye size={14} />
          <span>Log</span>
        </button>
      ),
      width: 80
    }
  ];

  return (
    <div className="content-body animate-fade-in" style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div>
          <h2 style={{ fontSize: '1.25rem', fontWeight: 600, color: 'var(--text-primary)' }}>Storico Esecuzioni Backup</h2>
          <p style={{ fontSize: '0.825rem', color: 'var(--text-secondary)' }}>
            Registro storico delle sincronizzazioni IMAP con metriche, durata e diagnostica errori
          </p>
        </div>

        <button onClick={() => loadRuns(page)} className="btn btn-secondary">
          <RefreshCw size={16} />
          <span>Aggiorna</span>
        </button>
      </div>

      {/* Filtri */}
      <div className="card" style={{ padding: 16 }}>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ minWidth: 200 }}>
            <label className="form-label">Filtro Casella</label>
            <select
              className="form-control"
              value={mailboxFilter}
              onChange={(e) => {
                setMailboxFilter(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Tutte le caselle</option>
              {mailboxes.map((mb) => (
                <option key={mb.id} value={mb.id}>
                  {mb.name} ({mb.emailAddress})
                </option>
              ))}
            </select>
          </div>

          <div style={{ minWidth: 180 }}>
            <label className="form-label">Filtro Stato</label>
            <select
              className="form-control"
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Tutti gli stati</option>
              <option value="SUCCESS">SUCCESS</option>
              <option value="FAILED">FAILED</option>
              <option value="RUNNING">RUNNING</option>
              <option value="PARTIAL">PARTIAL</option>
            </select>
          </div>
        </div>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <DataTable
          columns={columns}
          data={runs}
          loading={loading}
          emptyMessage="Nessuna esecuzione registrata"
          pagination={{
            page,
            totalPages,
            totalCount,
            onPageChange: (p) => setPage(p)
          }}
        />
      </div>

      {/* Modal Log Dettaglio Esecuzione */}
      <Modal
        isOpen={Boolean(selectedRun)}
        onClose={() => setSelectedRun(null)}
        title={`Dettaglio Esecuzione Backup (${selectedRun?.mailbox?.name || ''})`}
        maxWidth={750}
      >
        {selectedRun && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
                gap: 12,
                backgroundColor: 'var(--bg-app)',
                padding: 14,
                borderRadius: 'var(--radius-sm)',
                border: '1px solid var(--border-color)',
                fontSize: '0.85rem'
              }}
            >
              <div>
                <span style={{ color: 'var(--text-muted)' }}>Stato:</span>{' '}
                <AlertBadge status={selectedRun.status} />
              </div>
              <div>
                <span style={{ color: 'var(--text-muted)' }}>Email Importate:</span>{' '}
                <strong>{selectedRun.emailsImported}</strong>
              </div>
              <div>
                <span style={{ color: 'var(--text-muted)' }}>Email Saltate:</span>{' '}
                <span>{selectedRun.emailsSkipped}</span>
              </div>
              <div>
                <span style={{ color: 'var(--text-muted)' }}>Volume:</span>{' '}
                <span>{(selectedRun.totalSizeBytes / (1024 * 1024)).toFixed(2)} MB</span>
              </div>
            </div>

            {selectedRun.errorMessage && (
              <div
                style={{
                  padding: 12,
                  backgroundColor: 'var(--danger-bg)',
                  border: '1px solid var(--danger)',
                  borderRadius: 'var(--radius-sm)',
                  color: 'var(--danger)',
                  fontSize: '0.85rem'
                }}
              >
                <strong>Messaggio Errore:</strong> {selectedRun.errorMessage}
              </div>
            )}

            <div>
              <div style={{ fontSize: '0.85rem', fontWeight: 600, color: 'var(--text-secondary)', marginBottom: 6, display: 'flex', alignItems: 'center', gap: 6 }}>
                <Terminal size={16} />
                <span>Log Operativo di Sincronizzazione</span>
              </div>
              <pre
                style={{
                  backgroundColor: '#070b12',
                  color: '#e2e8f0',
                  padding: 14,
                  borderRadius: 'var(--radius-sm)',
                  border: '1px solid var(--border-color)',
                  fontSize: '0.775rem',
                  lineHeight: 1.5,
                  maxHeight: 300,
                  overflowY: 'auto',
                  whiteSpace: 'pre-wrap'
                }}
              >
                {selectedRun.logOutput || 'Nessun log testuale disponibile per questa esecuzione.'}
              </pre>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
};
