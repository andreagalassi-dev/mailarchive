/**
 * src/client/src/pages/BackupJobsPage.tsx
 * Gestione delle regole di schedulazione cron dei job di backup (solo ADMIN).
 */
import React, { useEffect, useState, useCallback } from 'react';
import { Clock, Plus, Pause, Play, Trash2, Edit } from 'lucide-react';
import { api } from '../services/api';
import { useToast } from '../components/Toast';
import { DataTable, Column } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { AlertBadge } from '../components/AlertBadge';

export const BackupJobsPage: React.FC = () => {
  const { showToast } = useToast();
  const [jobs, setJobs] = useState<any[]>([]);
  const [mailboxes, setMailboxes] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);

  // Modali
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [editingJob, setEditingJob] = useState<any | null>(null);

  // Form states
  const [mailboxId, setMailboxId] = useState('');
  const [scheduleCron, setScheduleCron] = useState('0 * * * *');
  const [submitting, setSubmitting] = useState(false);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [jobsRes, mbRes] = await Promise.all([
        api.get('/api/v1/backup-jobs'),
        api.get('/api/v1/mailboxes')
      ]);
      setJobs(jobsRes.data || []);
      setMailboxes(mbRes.data || []);
      if (mbRes.data && mbRes.data.length > 0 && !mailboxId) {
        setMailboxId(mbRes.data[0].id);
      }
    } catch (err: any) {
      showToast(err.message || 'Errore nel caricamento dei backup job', 'error');
    } finally {
      setLoading(false);
    }
  }, [mailboxId, showToast]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleCreateJob = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post('/api/v1/backup-jobs', {
        mailboxId,
        scheduleCron
      });
      showToast('Pianificazione di backup creata con successo', 'success');
      setIsCreateOpen(false);
      loadData();
    } catch (err: any) {
      showToast(err.message || 'Errore durante la creazione del job', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  const handleUpdateCron = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingJob) return;
    setSubmitting(true);
    try {
      await api.put(`/api/v1/backup-jobs/${editingJob.id}`, {
        scheduleCron
      });
      showToast('Schedulazione cron aggiornata', 'success');
      setEditingJob(null);
      loadData();
    } catch (err: any) {
      showToast(err.message || 'Errore durante l’aggiornamento del job', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  const handleTogglePause = async (job: any) => {
    const nextStatus = job.status === 'PAUSED' ? 'IDLE' : 'PAUSED';
    try {
      await api.put(`/api/v1/backup-jobs/${job.id}`, { status: nextStatus });
      showToast(`Job ${nextStatus === 'PAUSED' ? 'messo in pausa' : 'riattivato'}`, 'info');
      loadData();
    } catch (err: any) {
      showToast(err.message || 'Errore durante la modifica dello stato del job', 'error');
    }
  };

  const handleDeleteJob = async (jobId: string) => {
    if (!window.confirm('Sei sicuro di voler eliminare questa pianificazione di backup?')) return;
    try {
      await api.delete(`/api/v1/backup-jobs/${jobId}`);
      showToast('Pianificazione eliminata', 'info');
      loadData();
    } catch (err: any) {
      showToast(err.message || 'Errore durante l’eliminazione del job', 'error');
    }
  };

  const columns: Column<any>[] = [
    {
      key: 'mailbox',
      label: 'Casella Associata',
      render: (j) => (
        <div>
          <div style={{ fontWeight: 600 }}>{j.mailbox?.name || j.mailbox?.emailAddress}</div>
          <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>{j.mailbox?.emailAddress}</div>
        </div>
      )
    },
    {
      key: 'scheduleCron',
      label: 'Schedulazione Cron',
      render: (j) => (
        <span style={{ fontFamily: 'monospace', fontWeight: 600, backgroundColor: 'var(--bg-app)', padding: '3px 6px', borderRadius: 4 }}>
          {j.scheduleCron}
        </span>
      )
    },
    {
      key: 'status',
      label: 'Stato',
      render: (j) => <AlertBadge status={j.status} />
    },
    {
      key: 'nextRunAt',
      label: 'Prossima Esecuzione',
      render: (j) => (
        <span style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
          {j.nextRunAt ? new Date(j.nextRunAt).toLocaleString() : 'Non programmata'}
        </span>
      )
    },
    {
      key: 'lastRunAt',
      label: 'Ultima Esecuzione',
      render: (j) => (
        <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
          {j.lastRunAt ? new Date(j.lastRunAt).toLocaleString() : 'Mai eseguito'}
        </span>
      )
    },
    {
      key: 'actions',
      label: 'Azioni',
      render: (j) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <button
            onClick={() => handleTogglePause(j)}
            className="btn btn-secondary btn-sm"
            title={j.status === 'PAUSED' ? 'Riprendi schedulazione' : 'Metti in pausa'}
          >
            {j.status === 'PAUSED' ? <Play size={14} color="var(--success)" /> : <Pause size={14} color="var(--warning)" />}
            <span>{j.status === 'PAUSED' ? 'Riprendi' : 'Pausa'}</span>
          </button>
          <button
            onClick={() => {
              setEditingJob(j);
              setScheduleCron(j.scheduleCron);
            }}
            className="btn btn-secondary btn-sm"
            title="Modifica espressione cron"
          >
            <Edit size={14} />
          </button>
          <button
            onClick={() => handleDeleteJob(j.id)}
            className="btn btn-secondary btn-sm"
            title="Elimina job"
          >
            <Trash2 size={14} color="var(--danger)" />
          </button>
        </div>
      )
    }
  ];

  return (
    <div className="content-body animate-fade-in" style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div>
          <h2 style={{ fontSize: '1.25rem', fontWeight: 600, color: 'var(--text-primary)' }}>Pianificazione Backup Jobs</h2>
          <p style={{ fontSize: '0.825rem', color: 'var(--text-secondary)' }}>
            Regole cron automatiche per l'attivazione periodica della sincronizzazione delle caselle
          </p>
        </div>
        <button onClick={() => setIsCreateOpen(true)} className="btn btn-primary">
          <Plus size={16} />
          <span>Nuovo Job</span>
        </button>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <DataTable columns={columns} data={jobs} loading={loading} emptyMessage="Nessun job di backup configurato" />
      </div>

      {/* Modal Creazione Job */}
      <Modal isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} title="Crea Nuova Pianificazione Job">
        <form onSubmit={handleCreateJob}>
          <div className="form-group">
            <label className="form-label">Casella Email</label>
            <select
              required
              className="form-control"
              value={mailboxId}
              onChange={(e) => setMailboxId(e.target.value)}
            >
              {mailboxes.map((mb) => (
                <option key={mb.id} value={mb.id}>
                  {mb.name} ({mb.emailAddress})
                </option>
              ))}
            </select>
          </div>

          <div className="form-group">
            <label className="form-label">Espressione Cron (minuti ore giorno mese giorno-settimana)</label>
            <input
              type="text"
              required
              className="form-control"
              placeholder="0 * * * * (Ogni ora)"
              value={scheduleCron}
              onChange={(e) => setScheduleCron(e.target.value)}
            />
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', marginTop: 4 }}>
              Esempi: <code>0 * * * *</code> (ogni ora), <code>*/15 * * * *</code> (ogni 15 min), <code>0 2 * * *</code> (ogni notte alle 02:00)
            </div>
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 24 }}>
            <button type="button" onClick={() => setIsCreateOpen(false)} className="btn btn-secondary">
              Annulla
            </button>
            <button type="submit" disabled={submitting} className="btn btn-primary">
              {submitting ? 'Creazione...' : 'Crea Schedulazione'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Modal Modifica Cron */}
      <Modal isOpen={Boolean(editingJob)} onClose={() => setEditingJob(null)} title="Modifica Espressione Cron">
        <form onSubmit={handleUpdateCron}>
          <div className="form-group">
            <label className="form-label">Espressione Cron</label>
            <input
              type="text"
              required
              className="form-control"
              value={scheduleCron}
              onChange={(e) => setScheduleCron(e.target.value)}
            />
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 24 }}>
            <button type="button" onClick={() => setEditingJob(null)} className="btn btn-secondary">
              Annulla
            </button>
            <button type="submit" disabled={submitting} className="btn btn-primary">
              {submitting ? 'Aggiornamento...' : 'Salva Cron'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
};
