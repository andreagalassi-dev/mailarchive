/**
 * src/client/src/pages/AlertsPage.tsx
 * Gestione degli allarmi di sistema, tracciamento notifiche e risoluzione anomalie.
 */
import React, { useEffect, useState, useCallback } from 'react';
import { Bell, Plus, CheckCircle, RefreshCw } from 'lucide-react';
import { api } from '../services/api';
import { useToast } from '../components/Toast';
import { useAuth } from '../context/AuthContext';
import { DataTable, Column } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { AlertBadge } from '../components/AlertBadge';

export const AlertsPage: React.FC = () => {
  const { showToast } = useToast();
  const { user } = useAuth();
  const isAdmin = user?.role === 'ADMIN';

  const [alerts, setAlerts] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);

  // Filtri
  const [severityFilter, setSeverityFilter] = useState('');
  const [resolvedFilter, setResolvedFilter] = useState('false');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalCount, setTotalCount] = useState(0);

  // Modal Creazione Alert
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [title, setTitle] = useState('');
  const [message, setMessage] = useState('');
  const [severity, setSeverity] = useState<'INFO' | 'WARNING' | 'CRITICAL'>('WARNING');
  const [category, setCategory] = useState<'BACKUP' | 'SECURITY' | 'STORAGE' | 'SYSTEM'>('SYSTEM');
  const [submitting, setSubmitting] = useState(false);

  const loadAlerts = useCallback(async (targetPage: number) => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      params.set('page', String(targetPage));
      params.set('limit', '20');
      if (severityFilter) params.set('severity', severityFilter);
      if (resolvedFilter !== '') params.set('isResolved', resolvedFilter);

      const res = await api.get(`/api/v1/alerts?${params.toString()}`);
      setAlerts(res.data || []);
      setPage(res.pagination?.page || 1);
      setTotalPages(res.pagination?.totalPages || 1);
      setTotalCount(res.pagination?.totalCount || 0);
    } catch (err: any) {
      showToast(err.message || 'Errore nel caricamento degli allarmi', 'error');
    } finally {
      setLoading(false);
    }
  }, [severityFilter, resolvedFilter, showToast]);

  useEffect(() => {
    loadAlerts(page);
  }, [page, loadAlerts]);

  const handleResolveAlert = async (alertId: string) => {
    try {
      await api.put(`/api/v1/alerts/${alertId}/resolve`);
      showToast('Allarme contrassegnato come risolto', 'success');
      loadAlerts(page);
    } catch (err: any) {
      showToast(err.message || 'Errore durante la risoluzione dell’allarme', 'error');
    }
  };

  const handleCreateAlert = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post('/api/v1/alerts', {
        title,
        message,
        severity,
        category
      });
      showToast('Nuovo allarme registrato con successo', 'success');
      setIsCreateOpen(false);
      setTitle('');
      setMessage('');
      loadAlerts(1);
    } catch (err: any) {
      showToast(err.message || 'Errore durante la creazione dell’allarme', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  const columns: Column<any>[] = [
    {
      key: 'severity',
      label: 'Severità',
      render: (a) => <AlertBadge status={a.severity} />,
      width: 110
    },
    {
      key: 'title',
      label: 'Titolo & Messaggio',
      render: (a) => (
        <div>
          <div style={{ fontWeight: 600 }}>{a.title}</div>
          <div style={{ fontSize: '0.8rem', color: 'var(--text-secondary)', marginTop: 2 }}>
            {a.message}
          </div>
        </div>
      )
    },
    {
      key: 'category',
      label: 'Categoria',
      render: (a) => <span className="badge badge-secondary">{a.category}</span>,
      width: 120
    },
    {
      key: 'createdAt',
      label: 'Data Segnalazione',
      render: (a) => (
        <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
          {new Date(a.createdAt).toLocaleString()}
        </span>
      ),
      width: 170
    },
    {
      key: 'isResolved',
      label: 'Stato Risoluzione',
      render: (a) => (
        <div>
          <AlertBadge
            status={a.isResolved ? 'RESOLVED' : 'WARNING'}
            label={a.isResolved ? 'Risolto' : 'Aperto'}
          />
          {a.resolvedAt && (
            <div style={{ fontSize: '0.7rem', color: 'var(--text-muted)' }}>
              il {new Date(a.resolvedAt).toLocaleDateString()}
            </div>
          )}
        </div>
      ),
      width: 140
    },
    {
      key: 'actions',
      label: 'Azioni',
      render: (a) =>
        !a.isResolved && isAdmin ? (
          <button
            onClick={() => handleResolveAlert(a.id)}
            className="btn btn-secondary btn-sm"
            title="Segna come risolto"
          >
            <CheckCircle size={14} color="var(--success)" />
            <span>Risolvi</span>
          </button>
        ) : null,
      width: 100
    }
  ];

  return (
    <div className="content-body animate-fade-in" style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div>
          <h2 style={{ fontSize: '1.25rem', fontWeight: 600, color: 'var(--text-primary)' }}>Allarmi di Sistema</h2>
          <p style={{ fontSize: '0.825rem', color: 'var(--text-secondary)' }}>
            Monitoraggio anomalie operative, notifiche di sicurezza e risoluzione problemi ({totalCount} totali)
          </p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <button onClick={() => loadAlerts(page)} className="btn btn-secondary">
            <RefreshCw size={16} />
            <span>Aggiorna</span>
          </button>
          {isAdmin && (
            <button onClick={() => setIsCreateOpen(true)} className="btn btn-primary">
              <Plus size={16} />
              <span>Crea Allarme</span>
            </button>
          )}
        </div>
      </div>

      {/* Filtri */}
      <div className="card" style={{ padding: 16 }}>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ minWidth: 180 }}>
            <label className="form-label">Stato Allarme</label>
            <select
              className="form-control"
              value={resolvedFilter}
              onChange={(e) => {
                setResolvedFilter(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Tutti (Aperti e Risolti)</option>
              <option value="false">Solo Aperti (Non Risolti)</option>
              <option value="true">Solo Risolti</option>
            </select>
          </div>

          <div style={{ minWidth: 180 }}>
            <label className="form-label">Severità</label>
            <select
              className="form-control"
              value={severityFilter}
              onChange={(e) => {
                setSeverityFilter(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Tutte le severità</option>
              <option value="CRITICAL">CRITICAL</option>
              <option value="WARNING">WARNING</option>
              <option value="INFO">INFO</option>
            </select>
          </div>
        </div>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <DataTable
          columns={columns}
          data={alerts}
          loading={loading}
          emptyMessage="Nessun allarme corrispondente ai criteri"
          pagination={{
            page,
            totalPages,
            totalCount,
            onPageChange: (p) => setPage(p)
          }}
        />
      </div>

      {/* Modal Creazione Allarme Manuale */}
      <Modal isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} title="Genera Segnalazione Allarme">
        <form onSubmit={handleCreateAlert}>
          <div className="form-group">
            <label className="form-label">Titolo Allarme</label>
            <input
              type="text"
              required
              className="form-control"
              placeholder="es. Verifica integrità storage necessaria"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
            />
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">Severità</label>
              <select
                className="form-control"
                value={severity}
                onChange={(e) => setSeverity(e.target.value as any)}
              >
                <option value="INFO">INFO</option>
                <option value="WARNING">WARNING</option>
                <option value="CRITICAL">CRITICAL</option>
              </select>
            </div>

            <div className="form-group">
              <label className="form-label">Categoria</label>
              <select
                className="form-control"
                value={category}
                onChange={(e) => setCategory(e.target.value as any)}
              >
                <option value="SYSTEM">SYSTEM</option>
                <option value="BACKUP">BACKUP</option>
                <option value="SECURITY">SECURITY</option>
                <option value="STORAGE">STORAGE</option>
              </select>
            </div>
          </div>

          <div className="form-group">
            <label className="form-label">Descrizione / Messaggio Dettagliato</label>
            <textarea
              rows={4}
              required
              className="form-control"
              placeholder="Descrivi la causa dell'allarme e le azioni richieste..."
              value={message}
              onChange={(e) => setMessage(e.target.value)}
            />
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 24 }}>
            <button type="button" onClick={() => setIsCreateOpen(false)} className="btn btn-secondary">
              Annulla
            </button>
            <button type="submit" disabled={submitting} className="btn btn-primary">
              <Bell size={16} />
              <span>{submitting ? 'Registrazione...' : 'Crea Allarme'}</span>
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
};
