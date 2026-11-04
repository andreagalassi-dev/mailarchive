/**
 * src/client/src/pages/Dashboard.tsx
 * Panoramica e cruscotto KPI dello stato generale di MailArchive.
 */
import React, { useEffect, useState } from 'react';
import { Mail, Server, AlertTriangle, CheckCircle2, History, ArrowRight } from 'lucide-react';
import { api } from '../services/api';
import { AlertBadge } from '../components/AlertBadge';

export const Dashboard: React.FC = () => {
  const [stats, setStats] = useState({
    totalEmails: 0,
    totalMailboxes: 0,
    activeAlerts: 0,
    lastSuccessfulRun: null as any
  });
  const [recentRuns, setRecentRuns] = useState<any[]>([]);
  const [activeAlertsList, setActiveAlertsList] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const loadDashboardData = async () => {
      setLoading(true);
      try {
        const [emailStatsRes, mailboxesRes, alertsRes, runsRes] = await Promise.allSettled([
          api.get('/api/v1/emails/stats'),
          api.get('/api/v1/mailboxes'),
          api.get('/api/v1/alerts?isResolved=false&limit=5'),
          api.get('/api/v1/backup-runs?limit=5')
        ]);

        const totalEmails = emailStatsRes.status === 'fulfilled' ? emailStatsRes.value.data?.totalEmails || 0 : 0;
        const totalMailboxes = mailboxesRes.status === 'fulfilled' ? mailboxesRes.value.data?.length || 0 : 0;
        const activeAlerts = alertsRes.status === 'fulfilled' ? alertsRes.value.pagination?.totalCount || 0 : 0;
        const alertsList = alertsRes.status === 'fulfilled' ? alertsRes.value.data || [] : [];
        const runsList = runsRes.status === 'fulfilled' ? runsRes.value.data || [] : [];

        const lastSuccess = runsList.find((r: any) => r.status === 'SUCCESS') || null;

        setStats({
          totalEmails,
          totalMailboxes,
          activeAlerts,
          lastSuccessfulRun: lastSuccess
        });
        setRecentRuns(runsList);
        setActiveAlertsList(alertsList);
      } finally {
        setLoading(false);
      }
    };

    loadDashboardData();
  }, []);

  return (
    <div className="content-body animate-fade-in" style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      {/* KPI Cards Grid */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))',
          gap: 16
        }}
      >
        {/* KPI 1: Email Archiviate */}
        <div className="card" style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <div style={{ padding: 12, borderRadius: 10, backgroundColor: 'var(--accent-light)', color: 'var(--accent)' }}>
            <Mail size={26} />
          </div>
          <div>
            <div style={{ fontSize: '0.8rem', color: 'var(--text-secondary)', fontWeight: 500 }}>Email Archiviate</div>
            <div style={{ fontSize: '1.6rem', fontWeight: 700, color: 'var(--text-primary)' }}>
              {loading ? '...' : stats.totalEmails.toLocaleString()}
            </div>
          </div>
        </div>

        {/* KPI 2: Caselle Postali */}
        <div className="card" style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <div style={{ padding: 12, borderRadius: 10, backgroundColor: 'rgba(56, 189, 248, 0.15)', color: 'var(--info)' }}>
            <Server size={26} />
          </div>
          <div>
            <div style={{ fontSize: '0.8rem', color: 'var(--text-secondary)', fontWeight: 500 }}>Caselle IMAP Configurate</div>
            <div style={{ fontSize: '1.6rem', fontWeight: 700, color: 'var(--text-primary)' }}>
              {loading ? '...' : stats.totalMailboxes}
            </div>
          </div>
        </div>

        {/* KPI 3: Ultimo Backup Riuscito */}
        <div className="card" style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <div style={{ padding: 12, borderRadius: 10, backgroundColor: 'var(--success-bg)', color: 'var(--success)' }}>
            <CheckCircle2 size={26} />
          </div>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: '0.8rem', color: 'var(--text-secondary)', fontWeight: 500 }}>Ultimo Backup Concluso</div>
            <div style={{ fontSize: '0.95rem', fontWeight: 600, color: 'var(--text-primary)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
              {loading ? '...' : stats.lastSuccessfulRun ? new Date(stats.lastSuccessfulRun.completedAt || stats.lastSuccessfulRun.startedAt).toLocaleString() : 'Nessun backup'}
            </div>
          </div>
        </div>

        {/* KPI 4: Allarmi Attivi */}
        <div className="card" style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <div
            style={{
              padding: 12,
              borderRadius: 10,
              backgroundColor: stats.activeAlerts > 0 ? 'var(--danger-bg)' : 'var(--success-bg)',
              color: stats.activeAlerts > 0 ? 'var(--danger)' : 'var(--success)'
            }}
          >
            <AlertTriangle size={26} />
          </div>
          <div>
            <div style={{ fontSize: '0.8rem', color: 'var(--text-secondary)', fontWeight: 500 }}>Allarmi Non Risolti</div>
            <div style={{ fontSize: '1.6rem', fontWeight: 700, color: stats.activeAlerts > 0 ? 'var(--danger)' : 'var(--text-primary)' }}>
              {loading ? '...' : stats.activeAlerts}
            </div>
          </div>
        </div>
      </div>

      {/* Grid: Ultime Esecuzioni e Allarmi Recenti */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(420px, 1fr))', gap: 20 }}>
        {/* Tabella Ultime Esecuzioni */}
        <div className="card">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
            <h3 className="card-title" style={{ margin: 0 }}>
              <History size={18} />
              Ultime Esecuzioni Backup
            </h3>
            <a href="#backup-runs" className="btn btn-secondary btn-sm" style={{ padding: '4px 8px' }}>
              Vedi tutte <ArrowRight size={14} />
            </a>
          </div>

          <div className="table-container">
            <table className="table">
              <thead>
                <tr>
                  <th>Casella</th>
                  <th>Stato</th>
                  <th>Data Avvio</th>
                  <th>Email</th>
                </tr>
              </thead>
              <tbody>
                {recentRuns.length === 0 ? (
                  <tr>
                    <td colSpan={4} style={{ textAlign: 'center', color: 'var(--text-muted)' }}>
                      Nessuna esecuzione registrata
                    </td>
                  </tr>
                ) : (
                  recentRuns.map((r) => (
                    <tr key={r.id}>
                      <td style={{ fontWeight: 500 }}>{r.mailbox?.name || r.mailbox?.emailAddress}</td>
                      <td><AlertBadge status={r.status} /></td>
                      <td style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
                        {new Date(r.startedAt).toLocaleString()}
                      </td>
                      <td>{r.emailsImported} importate</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Lista Allarmi Attivi */}
        <div className="card">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
            <h3 className="card-title" style={{ margin: 0 }}>
              <AlertTriangle size={18} />
              Allarmi di Sistema Attivi
            </h3>
            <a href="#alerts" className="btn btn-secondary btn-sm" style={{ padding: '4px 8px' }}>
              Gestisci <ArrowRight size={14} />
            </a>
          </div>

          {activeAlertsList.length === 0 ? (
            <div style={{ textAlign: 'center', padding: '36px 0', color: 'var(--text-muted)' }}>
              <CheckCircle2 size={32} color="var(--success)" style={{ marginBottom: 8 }} />
              <div>Tutti i sistemi sono operativi. Nessun allarme aperto.</div>
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {activeAlertsList.map((alt) => (
                <div
                  key={alt.id}
                  style={{
                    padding: '12px 14px',
                    borderRadius: 'var(--radius-sm)',
                    border: '1px solid var(--border-color)',
                    backgroundColor: 'var(--bg-app)',
                    display: 'flex',
                    alignItems: 'flex-start',
                    justifyContent: 'space-between',
                    gap: 12
                  }}
                >
                  <div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                      <AlertBadge status={alt.severity} />
                      <span style={{ fontWeight: 600, fontSize: '0.9rem' }}>{alt.title}</span>
                    </div>
                    <div style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>{alt.message}</div>
                  </div>
                  <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                    {new Date(alt.createdAt).toLocaleDateString()}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
