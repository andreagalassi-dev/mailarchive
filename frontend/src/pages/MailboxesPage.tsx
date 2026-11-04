/**
 * src/client/src/pages/MailboxesPage.tsx
 * Gestione avanzata delle caselle email con supporto Google (Gmail) e IMAP Custom:
 * - Aggiunta e modifica caselle con configurazione server entrata (IMAP) e uscita (SMTP)
 * - Cifratura password a riposo con standard AES-256-GCM
 * - Collaudo preventivo connessione in tempo reale (Test Connessione)
 * - Rimozione dai backup con conservazione assoluta dei messaggi archiviati
 * - Eliminazione definitiva protetta da duplice conferma e codice 2FA (TOTP)
 */
import React, { useEffect, useState, useCallback } from 'react';
import {
  Server,
  Plus,
  RefreshCw,
  Edit2,
  Power,
  Trash2,
  ShieldCheck,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  Eye,
  EyeOff,
  Mail,
  ExternalLink,
  ShieldAlert,
  Inbox,
  FolderArchive
} from 'lucide-react';
import { api } from '../services/api';
import { useToast } from '../components/Toast';
import { DataTable, Column } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { AlertBadge } from '../components/AlertBadge';
import { ExportEmailsModal } from '../components/ExportEmailsModal';

export const MailboxesPage: React.FC = () => {
  const { showToast } = useToast();
  const [mailboxes, setMailboxes] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [syncingId, setSyncingId] = useState<string | null>(null);

  // Modali
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [editingMailbox, setEditingMailbox] = useState<any | null>(null);
  const [purgingMailbox, setPurgingMailbox] = useState<any | null>(null);
  const [exportModalMailboxId, setExportModalMailboxId] = useState<string | null>(null);

  // Form states
  const [provider, setProvider] = useState<'CUSTOM' | 'GMAIL'>('CUSTOM');
  const [name, setName] = useState('');
  const [emailAddress, setEmailAddress] = useState('');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);

  // Server posta in entrata (IMAP)
  const [incomingHost, setIncomingHost] = useState('');
  const [incomingPort, setIncomingPort] = useState(993);
  const [incomingEncryption, setIncomingEncryption] = useState<'SSL' | 'TLS' | 'STARTTLS' | 'NONE'>('SSL');

  // Server posta in uscita (SMTP - opzionale)
  const [outgoingHost, setOutgoingHost] = useState('');
  const [outgoingPort, setOutgoingPort] = useState(465);
  const [outgoingEncryption, setOutgoingEncryption] = useState<'SSL' | 'TLS' | 'STARTTLS' | 'NONE'>('SSL');

  // Test di connessione state
  const [testingConnection, setTestingConnection] = useState(false);
  const [testResult, setTestResult] = useState<{ success: boolean; message?: string; error?: string } | null>(null);

  // Purge 2FA state
  const [purgeConfirmationEmail, setPurgeConfirmationEmail] = useState('');
  const [purge2faCode, setPurge2faCode] = useState('');
  const [purgeAdminPassword, setPurgeAdminPassword] = useState('');
  const [purging, setPurging] = useState(false);

  const [submitting, setSubmitting] = useState(false);

  const loadMailboxes = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/api/v1/mailboxes');
      setMailboxes(res.data || []);
    } catch (err: any) {
      showToast(err.message || 'Errore nel caricamento delle caselle email', 'error');
    } finally {
      setLoading(false);
    }
  }, [showToast]);

  useEffect(() => {
    loadMailboxes();
  }, [loadMailboxes]);

  // Gestione selezione provider
  const handleSelectProvider = (selected: 'CUSTOM' | 'GMAIL') => {
    setProvider(selected);
    setTestResult(null);
    if (selected === 'GMAIL') {
      setIncomingHost('imap.gmail.com');
      setIncomingPort(993);
      setIncomingEncryption('SSL');
      setOutgoingHost('smtp.gmail.com');
      setOutgoingPort(465);
      setOutgoingEncryption('SSL');
      if (emailAddress && !username) {
        setUsername(emailAddress);
      }
    } else {
      if (incomingHost === 'imap.gmail.com') {
        setIncomingHost('');
        setOutgoingHost('');
      }
    }
  };

  const resetForm = () => {
    setProvider('CUSTOM');
    setName('');
    setEmailAddress('');
    setUsername('');
    setPassword('');
    setShowPassword(false);
    setIncomingHost('');
    setIncomingPort(993);
    setIncomingEncryption('SSL');
    setOutgoingHost('');
    setOutgoingPort(465);
    setOutgoingEncryption('SSL');
    setTestResult(null);
  };

  const handleOpenCreate = () => {
    resetForm();
    setIsCreateOpen(true);
  };

  const handleEmailChange = (val: string) => {
    setEmailAddress(val);
    // Se lo username era vuoto o identico alla vecchia email, aggiornalo
    if (!username || username === emailAddress) {
      setUsername(val);
    }
  };

  // Test connessione live
  const handleTestConnection = async () => {
    const hostToTest = provider === 'GMAIL' ? 'imap.gmail.com' : incomingHost.trim();
    if (!hostToTest) {
      showToast('Specifica l’host del server di posta in entrata prima di testare', 'warning');
      return;
    }
    if (!username.trim() || !password) {
      showToast('Inserisci username e password per eseguire il test', 'warning');
      return;
    }

    setTestingConnection(true);
    setTestResult(null);
    try {
      const res = await api.post('/api/v1/mailboxes/test-connection', {
        provider,
        emailAddress: emailAddress || undefined,
        username: username.trim(),
        password,
        incomingHost: hostToTest,
        incomingPort: Number(incomingPort),
        incomingEncryption
      });

      if (res.data?.success) {
        setTestResult({ success: true, message: res.data.message });
        showToast('Connessione IMAP verificata con successo!', 'success');
      } else {
        setTestResult({ success: false, error: res.data?.error || 'Test fallito' });
      }
    } catch (err: any) {
      setTestResult({ success: false, error: err.message || 'Errore durante il test di connessione' });
      showToast(err.message || 'Test di connessione fallito', 'error');
    } finally {
      setTestingConnection(false);
    }
  };

  // Creazione nuova casella
  const handleCreateMailbox = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post('/api/v1/mailboxes', {
        name: name.trim() || undefined,
        emailAddress: emailAddress.trim(),
        provider,
        username: username.trim(),
        password,
        incomingHost: provider === 'GMAIL' ? 'imap.gmail.com' : incomingHost.trim(),
        incomingPort: Number(incomingPort),
        incomingEncryption,
        outgoingHost: outgoingHost.trim() || undefined,
        outgoingPort: Number(outgoingPort) || undefined,
        outgoingEncryption
      });
      showToast('Casella email configurata e protetta con successo', 'success');
      setIsCreateOpen(false);
      resetForm();
      loadMailboxes();
    } catch (err: any) {
      showToast(err.message || 'Errore durante la creazione della casella', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  // Apertura modale di modifica
  const handleOpenEdit = (mb: any) => {
    setEditingMailbox(mb);
    setProvider(mb.provider === 'GMAIL' ? 'GMAIL' : 'CUSTOM');
    setName(mb.name || '');
    setEmailAddress(mb.emailAddress || '');
    setUsername(mb.username || '');
    setPassword('');
    setShowPassword(false);
    setIncomingHost(mb.incomingHost || mb.host || '');
    setIncomingPort(mb.incomingPort || mb.port || 993);
    setIncomingEncryption(mb.incomingEncryption || mb.encryption || 'SSL');
    setOutgoingHost(mb.outgoingHost || '');
    setOutgoingPort(mb.outgoingPort || 465);
    setOutgoingEncryption(mb.outgoingEncryption || 'SSL');
    setTestResult(null);
  };

  // Aggiornamento casella
  const handleUpdateMailbox = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingMailbox) return;
    setSubmitting(true);
    try {
      const payload: any = {
        name: name.trim() || undefined,
        emailAddress: emailAddress.trim(),
        provider,
        username: username.trim(),
        incomingHost: incomingHost.trim(),
        incomingPort: Number(incomingPort),
        incomingEncryption,
        outgoingHost: outgoingHost.trim() || undefined,
        outgoingPort: Number(outgoingPort) || undefined,
        outgoingEncryption
      };
      if (password && password.trim().length > 0) {
        payload.password = password;
      }
      await api.put(`/api/v1/mailboxes/${editingMailbox.id}`, payload);
      showToast('Parametri casella aggiornati con successo', 'success');
      setEditingMailbox(null);
      resetForm();
      loadMailboxes();
    } catch (err: any) {
      showToast(err.message || 'Errore durante l’aggiornamento della casella', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  // Rimozione logica dai backup ("Togliere casella" - conserva le email)
  const handleRemoveMailbox = async (mb: any) => {
    if (!confirm(`Vuoi rimuovere la casella "${mb.name}" dai backup attivi?\n\nTutte le email già archiviate verranno conservate e rimarranno consultabili nell'archivio.`)) {
      return;
    }
    try {
      await api.delete(`/api/v1/mailboxes/${mb.id}`);
      showToast(`Casella ${mb.name} rimossa dai backup. I messaggi archiviati sono stati conservati.`, 'info');
      loadMailboxes();
    } catch (err: any) {
      showToast(err.message || 'Errore durante la disattivazione', 'error');
    }
  };

  // Riattivazione casella
  const handleReactivateMailbox = async (mb: any) => {
    try {
      await api.put(`/api/v1/mailboxes/${mb.id}`, { isActive: true, status: 'ACTIVE' });
      showToast(`Casella ${mb.name} riattivata per i backup`, 'success');
      loadMailboxes();
    } catch (err: any) {
      showToast(err.message || 'Errore durante la riattivazione', 'error');
    }
  };

  // Apertura modale eliminazione definitiva con 2FA
  const handleOpenPurge = (mb: any) => {
    setPurgingMailbox(mb);
    setPurgeConfirmationEmail('');
    setPurge2faCode('');
    setPurgeAdminPassword('');
  };

  // Esecuzione eliminazione definitiva irreversibile
  const handleExecutePurge = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!purgingMailbox) return;

    setPurging(true);
    try {
      const res = await api.post(`/api/v1/mailboxes/${purgingMailbox.id}/purge`, {
        emailConfirmation: purgeConfirmationEmail.trim().toLowerCase(),
        twoFactorCode: purge2faCode.trim() || undefined,
        adminPassword: purgeAdminPassword || undefined
      });

      showToast(res.data?.message || 'Casella ed email archiviate eliminate definitivamente', 'success');
      setPurgingMailbox(null);
      loadMailboxes();
    } catch (err: any) {
      showToast(err.message || 'Errore durante l’eliminazione definitiva', 'error');
    } finally {
      setPurging(false);
    }
  };

  // Trigger manuale sincronizzazione
  const handleSyncMailbox = async (mbId: string, mbName: string) => {
    setSyncingId(mbId);
    try {
      const res = await api.post(`/api/v1/mailboxes/${mbId}/sync`);
      showToast(res.data?.message || `Sincronizzazione avviata in background per ${mbName}`, 'info');
      // Aggiorna elenco caselle per riflettere lo stato dopo breve intervallo
      setTimeout(() => {
        loadMailboxes();
        setSyncingId(null);
      }, 2000);
    } catch (err: any) {
      showToast(err.message || 'Errore durante la sincronizzazione', 'error');
      setSyncingId(null);
    }
  };

  const columns: Column<any>[] = [
    {
      key: 'name',
      label: 'Casella Email',
      render: (mb) => (
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <span style={{ fontWeight: 600, color: 'var(--text-primary)' }}>{mb.name}</span>
            {mb.provider === 'GMAIL' ? (
              <span
                style={{
                  fontSize: '0.7rem',
                  padding: '2px 6px',
                  borderRadius: 4,
                  background: 'rgba(234, 67, 53, 0.15)',
                  color: '#ea4335',
                  fontWeight: 600
                }}
              >
                Google Gmail
              </span>
            ) : (
              <span
                style={{
                  fontSize: '0.7rem',
                  padding: '2px 6px',
                  borderRadius: 4,
                  background: 'rgba(59, 130, 246, 0.15)',
                  color: '#3b82f6',
                  fontWeight: 600
                }}
              >
                IMAP Custom
              </span>
            )}
          </div>
          <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>{mb.emailAddress}</div>
        </div>
      )
    },
    {
      key: 'incomingHost',
      label: 'Server Entrata (IMAP)',
      render: (mb) => (
        <div style={{ fontSize: '0.825rem' }}>
          <div>
            <strong>{mb.incomingHost || mb.host}</strong>:{mb.incomingPort || mb.port}
          </div>
          <span style={{ fontSize: '0.725rem', color: 'var(--text-muted)' }}>
            Crittografia: {mb.incomingEncryption || mb.encryption}
          </span>
        </div>
      )
    },
    {
      key: 'emailsCount',
      label: 'Email Archiviate',
      render: (mb) => (
        <span
          style={{
            fontSize: '0.8rem',
            padding: '4px 8px',
            borderRadius: 6,
            background: 'var(--bg-input)',
            border: '1px solid var(--border-color)',
            display: 'inline-flex',
            alignItems: 'center',
            gap: 5
          }}
        >
          <Inbox size={13} color="var(--accent)" />
          <strong>{mb._count?.emails ?? 0}</strong> messaggi
        </span>
      )
    },
    {
      key: 'status',
      label: 'Stato',
      render: (mb) => {
        const isRemoved = mb.status === 'REMOVED' || !mb.isActive;
        return (
          <AlertBadge
            status={isRemoved ? 'DEACTIVATED' : 'ACTIVE'}
            label={isRemoved ? 'Backup Sospeso' : 'Attiva in Backup'}
          />
        );
      }
    },
    {
      key: 'lastSyncAt',
      label: 'Ultimo Backup',
      render: (mb) => (
        <div style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
          {mb.lastSyncAt ? (
            <>
              <div>{new Date(mb.lastSyncAt).toLocaleDateString()}</div>
              <div style={{ fontSize: '0.725rem', color: 'var(--text-muted)' }}>
                {new Date(mb.lastSyncAt).toLocaleTimeString()}
              </div>
            </>
          ) : (
            <span style={{ color: 'var(--text-muted)' }}>Mai eseguito</span>
          )}
        </div>
      )
    },
    {
      key: 'actions',
      label: 'Azioni',
      render: (mb) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <button
            onClick={() => handleSyncMailbox(mb.id, mb.name)}
            disabled={!mb.isActive || syncingId === mb.id}
            className="btn btn-primary btn-sm"
            title="Avvia sincronizzazione immediata (Posta in arrivo + inviata)"
          >
            <RefreshCw size={14} className={syncingId === mb.id ? 'animate-spin' : ''} />
            <span>{syncingId === mb.id ? 'Sync...' : 'Sync'}</span>
          </button>
          <button
            onClick={() => handleOpenEdit(mb)}
            className="btn btn-secondary btn-sm"
            title="Modifica parametri di configurazione"
          >
            <Edit2 size={14} />
          </button>
          {mb.isActive ? (
            <button
              onClick={() => handleRemoveMailbox(mb)}
              className="btn btn-secondary btn-sm"
              title="Togli dai backup (le email archiviate vengono preservate)"
            >
              <Power size={14} color="var(--warning)" />
            </button>
          ) : (
            <button
              onClick={() => handleReactivateMailbox(mb)}
              className="btn btn-secondary btn-sm"
              title="Riattiva per i backup"
            >
              <Power size={14} color="var(--success)" />
            </button>
          )}
          <button
            onClick={() => setExportModalMailboxId(mb.id)}
            className="btn btn-secondary btn-sm"
            title="Esporta archivio messaggi di questa casella (ZIP con .EML o MBOX)"
          >
            <FolderArchive size={14} color="var(--accent)" />
          </button>
          <button
            onClick={() => handleOpenPurge(mb)}
            className="btn btn-secondary btn-sm"
            style={{ color: 'var(--danger)', borderColor: 'rgba(239, 68, 68, 0.3)' }}
            title="Eliminazione definitiva con codice 2FA (cancella casella ed archivio)"
          >
            <Trash2 size={14} />
          </button>
        </div>
      )
    }
  ];

  return (
    <div className="content-body animate-fade-in" style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      {/* Intestazione pagina */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2 style={{ fontSize: '1.25rem', fontWeight: 600, color: 'var(--text-primary)' }}>
            Caselle Email da Archiviare
          </h2>
          <p style={{ fontSize: '0.825rem', color: 'var(--text-secondary)' }}>
            Configurazione caselle postali IMAP e Google (Gmail) con cifratura password AES-256-GCM
          </p>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          <button
            type="button"
            onClick={() => setExportModalMailboxId('all')}
            className="btn btn-secondary"
            style={{ display: 'flex', alignItems: 'center', gap: 8 }}
            title="Scarica tutte le email di tutte le caselle in un unico archivio ZIP"
          >
            <FolderArchive size={16} color="var(--accent)" />
            <span>Scarica Tutto (ZIP Globale)</span>
          </button>
          <button onClick={handleOpenCreate} className="btn btn-primary">
            <Plus size={16} />
            <span>Aggiungi Casella</span>
          </button>
        </div>
      </div>

      {/* Box informativo di sicurezza */}
      <div
        className="card"
        style={{
          padding: '12px 16px',
          background: 'rgba(37, 99, 235, 0.08)',
          borderColor: 'rgba(37, 99, 235, 0.25)',
          display: 'flex',
          alignItems: 'center',
          gap: 12
        }}
      >
        <ShieldCheck size={20} color="var(--accent)" style={{ flexShrink: 0 }} />
        <div style={{ fontSize: '0.825rem', color: 'var(--text-secondary)' }}>
          <strong>Protezione di Livello Bancario:</strong> Tutte le password memorizzate vengono cifrate a riposo con lo
          standard <strong>AES-256-GCM</strong> (con IV a 16 byte e tag di autenticazione crittografica). Le password non
          vengono mai restituite dalle API né stampate nei file di log del server.
        </div>
      </div>

      {/* Tabella elenco caselle */}
      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <DataTable
          columns={columns}
          data={mailboxes}
          loading={loading}
          emptyMessage="Nessuna casella email configurata. Clicca su 'Aggiungi Casella' per iniziare."
        />
      </div>

      {/* Modal Creazione Casella */}
      <Modal isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} title="Aggiungi Nuova Casella Email">
        <form onSubmit={handleCreateMailbox} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Selettore Provider */}
          <div className="form-group">
            <label className="form-label">Tipo di Account / Provider</label>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              <button
                type="button"
                onClick={() => handleSelectProvider('GMAIL')}
                style={{
                  padding: '10px 14px',
                  borderRadius: 8,
                  border: `2px solid ${provider === 'GMAIL' ? '#ea4335' : 'var(--border-color)'}`,
                  background: provider === 'GMAIL' ? 'rgba(234, 67, 53, 0.1)' : 'var(--bg-input)',
                  color: provider === 'GMAIL' ? '#ea4335' : 'var(--text-secondary)',
                  fontWeight: 600,
                  fontSize: '0.85rem',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: 8,
                  cursor: 'pointer'
                }}
              >
                <Mail size={16} color={provider === 'GMAIL' ? '#ea4335' : 'var(--text-muted)'} />
                <span>Google (Gmail)</span>
              </button>
              <button
                type="button"
                onClick={() => handleSelectProvider('CUSTOM')}
                style={{
                  padding: '10px 14px',
                  borderRadius: 8,
                  border: `2px solid ${provider === 'CUSTOM' ? 'var(--accent)' : 'var(--border-color)'}`,
                  background: provider === 'CUSTOM' ? 'var(--accent-light)' : 'var(--bg-input)',
                  color: provider === 'CUSTOM' ? 'var(--accent)' : 'var(--text-secondary)',
                  fontWeight: 600,
                  fontSize: '0.85rem',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: 8,
                  cursor: 'pointer'
                }}
              >
                <Server size={16} color={provider === 'CUSTOM' ? 'var(--accent)' : 'var(--text-muted)'} />
                <span>Altro Server (IMAP)</span>
              </button>
            </div>
          </div>

          {/* Istruzioni per Google (Gmail) */}
          {provider === 'GMAIL' && (
            <div
              style={{
                padding: 12,
                borderRadius: 8,
                background: 'rgba(234, 67, 53, 0.08)',
                border: '1px solid rgba(234, 67, 53, 0.25)',
                fontSize: '0.8rem',
                color: 'var(--text-secondary)'
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 600, color: '#ea4335', marginBottom: 4 }}>
                <ShieldCheck size={16} />
                Come collegare un account Google (Gmail) in sicurezza:
              </div>
              <ol style={{ paddingLeft: 18, marginTop: 4, display: 'flex', flexDirection: 'column', gap: 2 }}>
                <li>Assicurati che la verifica in 2 passaggi (2FA) sia attiva sul tuo account Google.</li>
                <li>
                  Genera una <strong>Password per le app</strong> sicura su{' '}
                  <a
                    href="https://myaccount.google.com/apppasswords"
                    target="_blank"
                    rel="noreferrer"
                    style={{ color: 'var(--accent)', textDecoration: 'underline' }}
                  >
                    myaccount.google.com/apppasswords
                  </a>
                </li>
                <li>Incolla la password generata (16 caratteri) nel campo Password qui sotto.</li>
              </ol>
            </div>
          )}

          {/* Dati account */}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">Nome Identificativo Casella</label>
              <input
                type="text"
                required
                className="form-control"
                placeholder="es. Casella Direzione"
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </div>
            <div className="form-group">
              <label className="form-label">Indirizzo Email</label>
              <input
                type="email"
                required
                className="form-control"
                placeholder="nome@dominio.it"
                value={emailAddress}
                onChange={(e) => handleEmailChange(e.target.value)}
              />
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">Nome Utente (Username)</label>
              <input
                type="text"
                required
                className="form-control"
                placeholder="es. nome@dominio.it"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
              />
            </div>
            <div className="form-group">
              <label className="form-label">
                Password {provider === 'GMAIL' ? '(Password per le app)' : ''}
              </label>
              <div style={{ position: 'relative' }}>
                <input
                  type={showPassword ? 'text' : 'password'}
                  required
                  className="form-control"
                  placeholder={provider === 'GMAIL' ? 'Password a 16 caratteri' : 'Password casella'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  style={{ paddingRight: 38 }}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  style={{
                    position: 'absolute',
                    right: 8,
                    top: '50%',
                    transform: 'translateY(-50%)',
                    background: 'none',
                    border: 'none',
                    color: 'var(--text-muted)',
                    cursor: 'pointer'
                  }}
                >
                  {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                </button>
              </div>
            </div>
          </div>

          {/* Sezione Posta in Entrata (IMAP) */}
          <div
            style={{
              background: 'var(--bg-input)',
              padding: 12,
              borderRadius: 8,
              border: '1px solid var(--border-color)'
            }}
          >
            <div style={{ fontWeight: 600, fontSize: '0.85rem', marginBottom: 8, color: 'var(--text-primary)' }}>
              Server Posta in Entrata (IMAP) — Effettua il backup di Ricevute ed Inviate
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 10 }}>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Server (Host)</label>
                <input
                  type="text"
                  required
                  disabled={provider === 'GMAIL'}
                  className="form-control"
                  placeholder="es. imap.example.com"
                  value={incomingHost}
                  onChange={(e) => setIncomingHost(e.target.value)}
                />
              </div>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Porta</label>
                <input
                  type="number"
                  required
                  disabled={provider === 'GMAIL'}
                  className="form-control"
                  value={incomingPort}
                  onChange={(e) => setIncomingPort(Number(e.target.value))}
                />
              </div>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Crittografia</label>
                <select
                  disabled={provider === 'GMAIL'}
                  className="form-control"
                  value={incomingEncryption}
                  onChange={(e) => setIncomingEncryption(e.target.value as any)}
                >
                  <option value="SSL">SSL</option>
                  <option value="TLS">STARTTLS</option>
                  <option value="NONE">NONE</option>
                </select>
              </div>
            </div>
          </div>

          {/* Sezione Posta in Uscita (SMTP - Opzionale) */}
          <div
            style={{
              background: 'var(--bg-input)',
              padding: 12,
              borderRadius: 8,
              border: '1px solid var(--border-color)'
            }}
          >
            <div style={{ fontWeight: 600, fontSize: '0.85rem', marginBottom: 8, color: 'var(--text-primary)' }}>
              Server Posta in Uscita (SMTP) — Scheda Account (Opzionale)
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 10 }}>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Server (Host)</label>
                <input
                  type="text"
                  disabled={provider === 'GMAIL'}
                  className="form-control"
                  placeholder="es. smtp.example.com"
                  value={outgoingHost}
                  onChange={(e) => setOutgoingHost(e.target.value)}
                />
              </div>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Porta</label>
                <input
                  type="number"
                  disabled={provider === 'GMAIL'}
                  className="form-control"
                  value={outgoingPort}
                  onChange={(e) => setOutgoingPort(Number(e.target.value))}
                />
              </div>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Crittografia</label>
                <select
                  disabled={provider === 'GMAIL'}
                  className="form-control"
                  value={outgoingEncryption}
                  onChange={(e) => setOutgoingEncryption(e.target.value as any)}
                >
                  <option value="SSL">SSL</option>
                  <option value="TLS">STARTTLS</option>
                  <option value="NONE">NONE</option>
                </select>
              </div>
            </div>
          </div>

          {/* Test Connessione Banner & Button */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
            <button
              type="button"
              onClick={handleTestConnection}
              disabled={testingConnection}
              className="btn btn-secondary"
              style={{ display: 'flex', alignItems: 'center', gap: 6 }}
            >
              <RefreshCw size={14} className={testingConnection ? 'animate-spin' : ''} />
              <span>{testingConnection ? 'Collaudo connessione in corso...' : 'Testa Connessione'}</span>
            </button>

            {testResult && (
              <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.8rem' }}>
                {testResult.success ? (
                  <span style={{ color: 'var(--success)', display: 'flex', alignItems: 'center', gap: 4 }}>
                    <CheckCircle2 size={16} /> {testResult.message}
                  </span>
                ) : (
                  <span style={{ color: 'var(--danger)', display: 'flex', alignItems: 'center', gap: 4 }}>
                    <XCircle size={16} /> {testResult.error}
                  </span>
                )}
              </div>
            )}
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 12 }}>
            <button type="button" onClick={() => setIsCreateOpen(false)} className="btn btn-secondary">
              Annulla
            </button>
            <button type="submit" disabled={submitting} className="btn btn-primary">
              {submitting ? 'Salvataggio in corso...' : 'Salva e Abilita Backup'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Modal Modifica Casella */}
      <Modal
        isOpen={Boolean(editingMailbox)}
        onClose={() => setEditingMailbox(null)}
        title={`Modifica Casella: ${editingMailbox?.name}`}
      >
        <form onSubmit={handleUpdateMailbox} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">Nome Identificativo</label>
              <input
                type="text"
                required
                className="form-control"
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </div>
            <div className="form-group">
              <label className="form-label">Indirizzo Email</label>
              <input
                type="email"
                required
                className="form-control"
                value={emailAddress}
                onChange={(e) => setEmailAddress(e.target.value)}
              />
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">Nome Utente (Username)</label>
              <input
                type="text"
                required
                className="form-control"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
              />
            </div>
            <div className="form-group">
              <label className="form-label">Nuova Password (lascia vuoto per non modificare)</label>
              <div style={{ position: 'relative' }}>
                <input
                  type={showPassword ? 'text' : 'password'}
                  className="form-control"
                  placeholder="••••••••••••••••"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  style={{ paddingRight: 38 }}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  style={{
                    position: 'absolute',
                    right: 8,
                    top: '50%',
                    transform: 'translateY(-50%)',
                    background: 'none',
                    border: 'none',
                    color: 'var(--text-muted)',
                    cursor: 'pointer'
                  }}
                >
                  {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                </button>
              </div>
            </div>
          </div>

          {/* Posta in entrata */}
          <div
            style={{
              background: 'var(--bg-input)',
              padding: 12,
              borderRadius: 8,
              border: '1px solid var(--border-color)'
            }}
          >
            <div style={{ fontWeight: 600, fontSize: '0.85rem', marginBottom: 8 }}>
              Server Posta in Entrata (IMAP)
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 10 }}>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Server (Host)</label>
                <input
                  type="text"
                  required
                  className="form-control"
                  value={incomingHost}
                  onChange={(e) => setIncomingHost(e.target.value)}
                />
              </div>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Porta</label>
                <input
                  type="number"
                  required
                  className="form-control"
                  value={incomingPort}
                  onChange={(e) => setIncomingPort(Number(e.target.value))}
                />
              </div>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Crittografia</label>
                <select
                  className="form-control"
                  value={incomingEncryption}
                  onChange={(e) => setIncomingEncryption(e.target.value as any)}
                >
                  <option value="SSL">SSL</option>
                  <option value="TLS">STARTTLS</option>
                  <option value="NONE">NONE</option>
                </select>
              </div>
            </div>
          </div>

          {/* Posta in uscita */}
          <div
            style={{
              background: 'var(--bg-input)',
              padding: 12,
              borderRadius: 8,
              border: '1px solid var(--border-color)'
            }}
          >
            <div style={{ fontWeight: 600, fontSize: '0.85rem', marginBottom: 8 }}>
              Server Posta in Uscita (SMTP - Opzionale)
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 10 }}>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Server (Host)</label>
                <input
                  type="text"
                  className="form-control"
                  value={outgoingHost}
                  onChange={(e) => setOutgoingHost(e.target.value)}
                />
              </div>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Porta</label>
                <input
                  type="number"
                  className="form-control"
                  value={outgoingPort}
                  onChange={(e) => setOutgoingPort(Number(e.target.value))}
                />
              </div>
              <div className="form-group">
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Crittografia</label>
                <select
                  className="form-control"
                  value={outgoingEncryption}
                  onChange={(e) => setOutgoingEncryption(e.target.value as any)}
                >
                  <option value="SSL">SSL</option>
                  <option value="TLS">STARTTLS</option>
                  <option value="NONE">NONE</option>
                </select>
              </div>
            </div>
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 12 }}>
            <button type="button" onClick={() => setEditingMailbox(null)} className="btn btn-secondary">
              Annulla
            </button>
            <button type="submit" disabled={submitting} className="btn btn-primary">
              {submitting ? 'Aggiornamento...' : 'Salva Modifiche'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Modal Eliminazione Definitiva con 2FA (Zona Pericolo) */}
      <Modal
        isOpen={Boolean(purgingMailbox)}
        onClose={() => setPurgingMailbox(null)}
        title="Eliminazione Definitiva Casella ed Archivio Email"
      >
        <form onSubmit={handleExecutePurge} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div
            style={{
              padding: 14,
              borderRadius: 8,
              background: 'rgba(239, 68, 68, 0.12)',
              border: '1px solid rgba(239, 68, 68, 0.3)',
              display: 'flex',
              flexDirection: 'column',
              gap: 8
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 700, color: 'var(--danger)' }}>
              <ShieldAlert size={20} />
              ATTENZIONE: OPERAZIONE DISTRUTTIVA IRREVERSIBILE
            </div>
            <p style={{ fontSize: '0.825rem', color: 'var(--text-secondary)' }}>
              Stai per eliminare definitivamente la casella <strong>{purgingMailbox?.emailAddress}</strong> assieme a
              tutte le <strong>{purgingMailbox?._count?.emails ?? 0} email archiviate</strong> e ai relativi file su disco.
              Questa azione distruggerà i dati per sempre.
            </p>
          </div>

          <div className="form-group">
            <label className="form-label" style={{ color: 'var(--danger)', fontWeight: 600 }}>
              1. Duplice conferma: Digita l'indirizzo email della casella
            </label>
            <input
              type="text"
              required
              className="form-control"
              placeholder={purgingMailbox?.emailAddress}
              value={purgeConfirmationEmail}
              onChange={(e) => setPurgeConfirmationEmail(e.target.value)}
            />
          </div>

          <div className="form-group">
            <label className="form-label" style={{ fontWeight: 600 }}>
              2. Codice di Sicurezza 2FA (TOTP Authenticator)
            </label>
            <input
              type="text"
              className="form-control"
              placeholder="es. 123456 (se 2FA attivo)"
              value={purge2faCode}
              onChange={(e) => setPurge2faCode(e.target.value)}
            />
            <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
              Se non hai ancora abilitato il TOTP sul tuo account admin, inserisci la password dell'amministratore qui sotto.
            </span>
          </div>

          <div className="form-group">
            <label className="form-label" style={{ fontSize: '0.8rem' }}>
              Oppure Password Amministratore (se 2FA non ancora configurato)
            </label>
            <input
              type="password"
              className="form-control"
              placeholder="Password del tuo account amministratore"
              value={purgeAdminPassword}
              onChange={(e) => setPurgeAdminPassword(e.target.value)}
            />
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16 }}>
            <button type="button" onClick={() => setPurgingMailbox(null)} className="btn btn-secondary">
              Annulla
            </button>
            <button
              type="submit"
              disabled={
                purging ||
                purgeConfirmationEmail.trim().toLowerCase() !== purgingMailbox?.emailAddress.toLowerCase() ||
                (!purge2faCode.trim() && !purgeAdminPassword)
              }
              className="btn btn-danger"
              style={{
                background: 'var(--danger)',
                color: '#fff',
                borderColor: 'var(--danger)'
              }}
            >
              {purging ? 'Distruzione dati in corso...' : 'Distruggi Definitivamente'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Modale Esportazione Massiva ZIP / MBOX */}
      <ExportEmailsModal
        isOpen={exportModalMailboxId !== null}
        onClose={() => setExportModalMailboxId(null)}
        mailboxes={mailboxes}
        defaultMailboxId={exportModalMailboxId || 'all'}
      />
    </div>
  );
};
