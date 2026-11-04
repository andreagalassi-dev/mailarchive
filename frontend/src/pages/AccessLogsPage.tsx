/**
 * src/client/src/pages/AccessLogsPage.tsx
 * Consultazione dei registri immutabili di AuditLog e dei tentativi di accesso.
 */
import React, { useEffect, useState, useCallback } from 'react';
import { ShieldCheck, UserCheck, Eye, RefreshCw } from 'lucide-react';
import { api } from '../services/api';
import { useToast } from '../components/Toast';
import { DataTable, Column } from '../components/DataTable';
import { Modal } from '../components/Modal';

export const AccessLogsPage: React.FC = () => {
  const { showToast } = useToast();
  const [tab, setTab] = useState<'audit' | 'logins'>('audit');

  // Audit Logs State
  const [auditLogs, setAuditLogs] = useState<any[]>([]);
  const [auditPage, setAuditPage] = useState(1);
  const [auditTotalPages, setAuditTotalPages] = useState(1);
  const [auditTotalCount, setAuditTotalCount] = useState(0);

  // Login Attempts State
  const [loginAttempts, setLoginAttempts] = useState<any[]>([]);
  const [loginPage, setLoginPage] = useState(1);
  const [loginTotalPages, setLoginTotalPages] = useState(1);
  const [loginTotalCount, setLoginTotalCount] = useState(0);

  const [loading, setLoading] = useState(false);
  const [selectedDetails, setSelectedDetails] = useState<any | null>(null);

  const loadAuditLogs = useCallback(async (page: number) => {
    setLoading(true);
    try {
      const res = await api.get(`/api/v1/audit-logs?page=${page}&limit=20`);
      setAuditLogs(res.data || []);
      setAuditPage(res.pagination?.page || 1);
      setAuditTotalPages(res.pagination?.totalPages || 1);
      setAuditTotalCount(res.pagination?.totalCount || 0);
    } catch (err: any) {
      showToast(err.message || 'Errore nel caricamento del registro audit', 'error');
    } finally {
      setLoading(false);
    }
  }, [showToast]);

  const loadLoginAttempts = useCallback(async (page: number) => {
    setLoading(true);
    try {
      const res = await api.get(`/api/v1/audit-logs/login-attempts?page=${page}&limit=20`);
      setLoginAttempts(res.data || []);
      setLoginPage(res.pagination?.page || 1);
      setLoginTotalPages(res.pagination?.totalPages || 1);
      setLoginTotalCount(res.pagination?.totalCount || 0);
    } catch (err: any) {
      showToast(err.message || 'Errore nel caricamento dei tentativi di accesso', 'error');
    } finally {
      setLoading(false);
    }
  }, [showToast]);

  useEffect(() => {
    if (tab === 'audit') {
      loadAuditLogs(auditPage);
    } else {
      loadLoginAttempts(loginPage);
    }
  }, [tab, auditPage, loginPage, loadAuditLogs, loadLoginAttempts]);

  const auditColumns: Column<any>[] = [
    {
      key: 'timestamp',
      label: 'Data / Ora',
      render: (r) => (
        <span style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
          {new Date(r.timestamp).toLocaleString()}
        </span>
      )
    },
    {
      key: 'action',
      label: 'Azione',
      render: (r) => <span style={{ fontWeight: 600, color: 'var(--text-primary)' }}>{r.action}</span>
    },
    {
      key: 'userEmail',
      label: 'Utente',
      render: (r) => r.userEmail || '<sistema>'
    },
    {
      key: 'resourceType',
      label: 'Risorsa',
      render: (r) => (
        <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
          {r.resourceType ? `${r.resourceType}${r.resourceId ? ` (${r.resourceId.slice(0, 8)}...)` : ''}` : '-'}
        </span>
      )
    },
    {
      key: 'ipAddress',
      label: 'Indirizzo IP',
      render: (r) => <span style={{ fontFamily: 'monospace', fontSize: '0.8rem' }}>{r.ipAddress || '-'}</span>
    },
    {
      key: 'details',
      label: 'Dettagli',
      render: (r) => {
        const detailsData = r.details ?? r.detailsJson;
        if (!detailsData) return '-';
        return (
          <button
            onClick={() => setSelectedDetails({ action: r.action, data: detailsData })}
            className="btn btn-secondary btn-sm"
          >
            <Eye size={14} />
            <span>Mostra</span>
          </button>
        );
      }
    }
  ];

  const loginColumns: Column<any>[] = [
    {
      key: 'attemptedAt',
      label: 'Data / Ora',
      render: (r) => (
        <span style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
          {new Date(r.attemptedAt).toLocaleString()}
        </span>
      )
    },
    {
      key: 'email',
      label: 'Email Tentativo',
      render: (r) => <span style={{ fontWeight: 600 }}>{r.email}</span>
    },
    {
      key: 'success',
      label: 'Esito',
      render: (r) => (
        <span className={`badge ${r.success ? 'badge-success' : 'badge-danger'}`}>
          {r.success ? 'Riuscito' : 'Fallito'}
        </span>
      )
    },
    {
      key: 'ipAddress',
      label: 'Indirizzo IP',
      render: (r) => <span style={{ fontFamily: 'monospace', fontSize: '0.8rem' }}>{r.ipAddress}</span>
    },
    {
      key: 'userAgent',
      label: 'User Agent',
      render: (r) => (
        <span
          style={{
            fontSize: '0.75rem',
            color: 'var(--text-muted)',
            display: 'inline-block',
            maxWidth: 240,
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            whiteSpace: 'nowrap'
          }}
          title={r.userAgent}
        >
          {r.userAgent || '-'}
        </span>
      )
    }
  ];

  return (
    <div className="content-body animate-fade-in" style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div>
          <h2 style={{ fontSize: '1.25rem', fontWeight: 600, color: 'var(--text-primary)' }}>
            Registri di Accesso e Audit
          </h2>
          <p style={{ fontSize: '0.825rem', color: 'var(--text-secondary)' }}>
            Tracciamento immutabile di tutte le azioni amministrative e dei tentativi di accesso al sistema
          </p>
        </div>

        <button
          onClick={() => (tab === 'audit' ? loadAuditLogs(auditPage) : loadLoginAttempts(loginPage))}
          className="btn btn-secondary"
        >
          <RefreshCw size={16} />
          <span>Aggiorna</span>
        </button>
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 12, borderBottom: '1px solid var(--border-color)', paddingBottom: 8 }}>
        <button
          onClick={() => setTab('audit')}
          className={`btn ${tab === 'audit' ? 'btn-primary' : 'btn-secondary'}`}
        >
          <ShieldCheck size={16} />
          <span>Audit Log Eventi</span>
        </button>
        <button
          onClick={() => setTab('logins')}
          className={`btn ${tab === 'logins' ? 'btn-primary' : 'btn-secondary'}`}
        >
          <UserCheck size={16} />
          <span>Tentativi di Login</span>
        </button>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        {tab === 'audit' ? (
          <DataTable
            columns={auditColumns}
            data={auditLogs}
            loading={loading}
            emptyMessage="Nessun evento di audit registrato"
            pagination={{
              page: auditPage,
              totalPages: auditTotalPages,
              totalCount: auditTotalCount,
              onPageChange: (p) => setAuditPage(p)
            }}
          />
        ) : (
          <DataTable
            columns={loginColumns}
            data={loginAttempts}
            loading={loading}
            emptyMessage="Nessun tentativo di accesso registrato"
            pagination={{
              page: loginPage,
              totalPages: loginTotalPages,
              totalCount: loginTotalCount,
              onPageChange: (p) => setLoginPage(p)
            }}
          />
        )}
      </div>

      {/* Modal Dettagli JSON */}
      <Modal
        isOpen={Boolean(selectedDetails)}
        onClose={() => setSelectedDetails(null)}
        title={`Dettaglio Evento: ${selectedDetails?.action}`}
      >
        <pre
          style={{
            backgroundColor: 'var(--bg-app)',
            padding: 16,
            borderRadius: 'var(--radius-sm)',
            border: '1px solid var(--border-color)',
            fontSize: '0.8rem',
            overflowX: 'auto',
            color: 'var(--text-primary)',
            whiteSpace: 'pre-wrap',
            wordBreak: 'break-word',
            maxHeight: 400
          }}
        >
          {selectedDetails
            ? typeof selectedDetails.data === 'string'
              ? (() => {
                  try {
                    return JSON.stringify(JSON.parse(selectedDetails.data), null, 2);
                  } catch {
                    return selectedDetails.data;
                  }
                })()
              : JSON.stringify(selectedDetails.data, null, 2)
            : ''}
        </pre>
      </Modal>
    </div>
  );
};
