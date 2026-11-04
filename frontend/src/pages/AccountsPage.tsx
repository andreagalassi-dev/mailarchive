/**
 * src/client/src/pages/AccountsPage.tsx
 * Gestione degli account utenti e ruoli RBAC (solo ADMIN).
 */
import React, { useEffect, useState, useCallback } from 'react';
import { UserPlus, KeyRound, Shield, UserX, UserCheck, Smartphone, Copy, Check, ShieldCheck } from 'lucide-react';
import { api } from '../services/api';
import { useToast } from '../components/Toast';
import { DataTable, Column } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { AlertBadge } from '../components/AlertBadge';

export const AccountsPage: React.FC = () => {
  const { showToast } = useToast();
  const [users, setUsers] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  // Modali
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isPasswordModalOpen, setIsPasswordModalOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<any | null>(null);

  // 2FA state
  const [is2faModalOpen, setIs2faModalOpen] = useState(false);
  const [twoFactorStatus, setTwoFactorStatus] = useState<any | null>(null);
  const [totpSetupData, setTotpSetupData] = useState<{ secret: string; uri: string; qrCodeDataUrl: string } | null>(null);
  const [confirmCode, setConfirmCode] = useState('');
  const [disablePassword, setDisablePassword] = useState('');
  const [loading2fa, setLoading2fa] = useState(false);
  const [copiedSecret, setCopiedSecret] = useState(false);

  // Form states
  const [newEmail, setNewEmail] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newRole, setNewRole] = useState<'ADMIN' | 'VIEWER'>('VIEWER');
  const [resetPasswordVal, setResetPasswordVal] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const loadUsers = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/api/v1/accounts');
      setUsers(res.data || []);
    } catch (err: any) {
      showToast(err.message || 'Errore nel caricamento degli utenti', 'error');
    } finally {
      setLoading(false);
    }
  }, [showToast]);

  useEffect(() => {
    loadUsers();
  }, [loadUsers]);

  const handleCreateUser = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post('/api/v1/accounts', {
        email: newEmail,
        password: newPassword,
        role: newRole
      });
      showToast('Nuovo utente creato con successo', 'success');
      setIsCreateOpen(false);
      setNewEmail('');
      setNewPassword('');
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Errore durante la creazione utente', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  const handleResetPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedUser) return;
    setSubmitting(true);
    try {
      await api.put(`/api/v1/accounts/${selectedUser.id}`, {
        password: resetPasswordVal
      });
      showToast(`Password aggiornata per ${selectedUser.email}`, 'success');
      setIsPasswordModalOpen(false);
      setResetPasswordVal('');
    } catch (err: any) {
      showToast(err.message || 'Errore durante il reset della password', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  const handleToggleActive = async (user: any) => {
    try {
      if (user.isActive) {
        await api.delete(`/api/v1/accounts/${user.id}`);
        showToast(`Account ${user.email} disattivato`, 'info');
      } else {
        await api.put(`/api/v1/accounts/${user.id}`, { isActive: true });
        showToast(`Account ${user.email} riattivato`, 'success');
      }
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Errore durante l’aggiornamento di stato', 'error');
    }
  };

  const handleToggleRole = async (user: any) => {
    const targetRole = user.role === 'ADMIN' ? 'VIEWER' : 'ADMIN';
    try {
      await api.put(`/api/v1/accounts/${user.id}`, { role: targetRole });
      showToast(`Ruolo di ${user.email} modificato in ${targetRole}`, 'success');
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Errore durante il cambio ruolo', 'error');
    }
  };

  const open2faModal = async () => {
    setIs2faModalOpen(true);
    setLoading2fa(true);
    setCopiedSecret(false);
    setConfirmCode('');
    setDisablePassword('');
    try {
      const statusRes = await api.get('/api/v1/auth/2fa/status');
      setTwoFactorStatus(statusRes.data);
      if (!statusRes.data?.totpEnabled) {
        const setupRes = await api.post('/api/v1/auth/2fa/setup');
        setTotpSetupData(setupRes.data);
      }
    } catch (err: any) {
      showToast(err.message || 'Errore recupero configurazione 2FA', 'error');
    } finally {
      setLoading2fa(false);
    }
  };

  const handleEnableTotp = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!totpSetupData) return;
    setSubmitting(true);
    try {
      await api.post('/api/v1/auth/2fa/enable', {
        secret: totpSetupData.secret,
        code: confirmCode
      });
      showToast('Autenticazione 2FA con App Authenticator abilitata!', 'success');
      setIs2faModalOpen(false);
      setConfirmCode('');
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Codice errato. Verifica il codice a 6 cifre.', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDisableTotp = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post('/api/v1/auth/2fa/disable', {
        password: disablePassword
      });
      showToast('2FA disattivato.', 'info');
      setIs2faModalOpen(false);
      setDisablePassword('');
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Password non corretta', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  const columns: Column<any>[] = [
    {
      key: 'email',
      label: 'Email Utente',
      render: (u) => (
        <div>
          <div style={{ fontWeight: 600 }}>{u.email}</div>
          <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>ID: {u.id}</div>
        </div>
      )
    },
    {
      key: 'role',
      label: 'Ruolo RBAC',
      render: (u) => <AlertBadge status={u.role} />
    },
    {
      key: 'totpEnabled',
      label: 'Sicurezza 2FA',
      render: (u) => (
        <AlertBadge
          status={u.totpEnabled ? 'ACTIVE' : 'IDLE'}
          label={u.totpEnabled ? 'App Authenticator' : 'Email OTP'}
        />
      )
    },
    {
      key: 'isActive',
      label: 'Stato',
      render: (u) => (
        <AlertBadge
          status={u.isActive ? 'ACTIVE' : 'DEACTIVATED'}
          label={u.isActive ? 'Attivo' : 'Disattivato'}
        />
      )
    },
    {
      key: 'createdAt',
      label: 'Data Creazione',
      render: (u) => <span style={{ fontSize: '0.85rem' }}>{new Date(u.createdAt).toLocaleDateString()}</span>
    },
    {
      key: 'actions',
      label: 'Azioni',
      render: (u) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <button
            onClick={() => handleToggleRole(u)}
            className="btn btn-secondary btn-sm"
            title="Cambia Ruolo"
          >
            <Shield size={14} />
            <span>Ruolo</span>
          </button>
          <button
            onClick={() => {
              setSelectedUser(u);
              setIsPasswordModalOpen(true);
            }}
            className="btn btn-secondary btn-sm"
            title="Reimposta Password"
          >
            <KeyRound size={14} />
            <span>Password</span>
          </button>
          <button
            onClick={() => handleToggleActive(u)}
            className={`btn btn-sm ${u.isActive ? 'btn-secondary' : 'btn-primary'}`}
            title={u.isActive ? 'Disattiva utente' : 'Riattiva utente'}
          >
            {u.isActive ? <UserX size={14} color="var(--danger)" /> : <UserCheck size={14} />}
            <span>{u.isActive ? 'Disattiva' : 'Attiva'}</span>
          </button>
        </div>
      )
    }
  ];

  return (
    <div className="content-body animate-fade-in" style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div>
          <h2 style={{ fontSize: '1.25rem', fontWeight: 600, color: 'var(--text-primary)' }}>Gestione Utenti</h2>
          <p style={{ fontSize: '0.825rem', color: 'var(--text-secondary)' }}>
            Configurazione accessi amministrativi e visualizzatori con permessi RBAC
          </p>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <button onClick={open2faModal} className="btn btn-secondary">
            <Smartphone size={16} />
            <span>Sicurezza 2FA Personale</span>
          </button>
          <button onClick={() => setIsCreateOpen(true)} className="btn btn-primary">
            <UserPlus size={16} />
            <span>Nuovo Account</span>
          </button>
        </div>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <DataTable columns={columns} data={users} loading={loading} emptyMessage="Nessun utente registrato" />
      </div>

      {/* Modal Creazione Utente */}
      <Modal isOpen={isCreateOpen} onClose={() => setIsCreateOpen(false)} title="Crea Nuovo Account Utente">
        <form onSubmit={handleCreateUser}>
          <div className="form-group">
            <label className="form-label">Email Utente</label>
            <input
              type="email"
              required
              className="form-control"
              placeholder="operatore@azienda.it"
              value={newEmail}
              onChange={(e) => setNewEmail(e.target.value)}
            />
          </div>

          <div className="form-group">
            <label className="form-label">Password Iniziale</label>
            <input
              type="password"
              required
              minLength={8}
              className="form-control"
              placeholder="Almeno 8 caratteri"
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
            />
          </div>

          <div className="form-group">
            <label className="form-label">Ruolo RBAC</label>
            <select
              className="form-control"
              value={newRole}
              onChange={(e) => setNewRole(e.target.value as any)}
            >
              <option value="VIEWER">VIEWER (Sola consultazione email e storico)</option>
              <option value="ADMIN">ADMIN (Accesso completo e configurazioni)</option>
            </select>
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 24 }}>
            <button type="button" onClick={() => setIsCreateOpen(false)} className="btn btn-secondary">
              Annulla
            </button>
            <button type="submit" disabled={submitting} className="btn btn-primary">
              {submitting ? 'Creazione...' : 'Crea Utente'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Modal Reset Password */}
      <Modal
        isOpen={isPasswordModalOpen}
        onClose={() => setIsPasswordModalOpen(false)}
        title={`Reimposta Password per ${selectedUser?.email}`}
      >
        <form onSubmit={handleResetPassword}>
          <div className="form-group">
            <label className="form-label">Nuova Password</label>
            <input
              type="password"
              required
              minLength={8}
              className="form-control"
              placeholder="Inserisci nuova password"
              value={resetPasswordVal}
              onChange={(e) => setResetPasswordVal(e.target.value)}
            />
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 24 }}>
            <button type="button" onClick={() => setIsPasswordModalOpen(false)} className="btn btn-secondary">
              Annulla
            </button>
            <button type="submit" disabled={submitting} className="btn btn-primary">
              {submitting ? 'Salvataggio...' : 'Aggiorna Password'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Modal Configurazione 2FA */}
      <Modal
        isOpen={is2faModalOpen}
        onClose={() => setIs2faModalOpen(false)}
        title="Configurazione Autenticazione a Due Fattori (2FA)"
      >
        {loading2fa ? (
          <div style={{ textAlign: 'center', padding: 30, color: 'var(--text-secondary)' }}>
            Caricamento impostazioni di sicurezza...
          </div>
        ) : twoFactorStatus?.totpEnabled ? (
          <div>
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 12,
                padding: '14px 16px',
                backgroundColor: 'rgba(16, 185, 129, 0.12)',
                border: '1px solid #10b981',
                borderRadius: 'var(--radius-sm)',
                marginBottom: 20
              }}
            >
              <ShieldCheck size={24} color="#10b981" style={{ flexShrink: 0 }} />
              <div>
                <div style={{ fontWeight: 600, color: '#10b981' }}>2FA con App Authenticator ATTIVO</div>
                <div style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
                  Il tuo account è protetto con App Authenticator e codice di verifica monouso.
                </div>
              </div>
            </div>

            <form onSubmit={handleDisableTotp}>
              <p style={{ fontSize: '0.85rem', color: 'var(--text-secondary)', marginBottom: 12 }}>
                Se desideri disattivare l'applicazione Authenticator per questo account, conferma inserendo la tua
                password attuale:
              </p>
              <div className="form-group">
                <label className="form-label">Password Attuale</label>
                <input
                  type="password"
                  required
                  className="form-control"
                  placeholder="Inserisci la tua password per confermare"
                  value={disablePassword}
                  onChange={(e) => setDisablePassword(e.target.value)}
                />
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 20 }}>
                <button type="button" onClick={() => setIs2faModalOpen(false)} className="btn btn-secondary">
                  Chiudi
                </button>
                <button type="submit" disabled={submitting || !disablePassword} className="btn btn-danger">
                  {submitting ? 'Disattivazione...' : 'Disattiva 2FA'}
                </button>
              </div>
            </form>
          </div>
        ) : (
          <div>
            <p style={{ fontSize: '0.85rem', color: 'var(--text-secondary)', marginBottom: 16 }}>
              Proteggi il tuo account richiedendo un codice di sicurezza generato dall'app sul tuo smartphone ad ogni accesso.
            </p>

            {totpSetupData && (
              <div>
                <div style={{ textAlign: 'center', marginBottom: 16, backgroundColor: '#ffffff', padding: 16, borderRadius: 12, display: 'inline-block', width: '100%', border: '1px solid var(--border-color)' }}>
                  <img
                    src={totpSetupData.qrCodeDataUrl}
                    alt="QR Code TOTP"
                    style={{ width: 190, height: 190, margin: '0 auto', display: 'block' }}
                  />
                  <div style={{ fontSize: '0.75rem', color: '#64748b', marginTop: 8 }}>
                    Inquadra con Google Authenticator, Microsoft Authenticator o 1Password
                  </div>
                </div>

                <div style={{ marginBottom: 20 }}>
                  <label className="form-label" style={{ fontSize: '0.8rem' }}>Chiave segreta di configurazione manuale</label>
                  <div style={{ display: 'flex', gap: 8 }}>
                    <input
                      type="text"
                      readOnly
                      className="form-control"
                      value={totpSetupData.secret}
                      style={{ fontFamily: 'monospace', fontSize: '0.85rem' }}
                    />
                    <button
                      type="button"
                      className="btn btn-secondary"
                      onClick={() => {
                        navigator.clipboard.writeText(totpSetupData.secret);
                        setCopiedSecret(true);
                        setTimeout(() => setCopiedSecret(false), 2000);
                      }}
                      title="Copia chiave segreta"
                    >
                      {copiedSecret ? <Check size={16} color="#10b981" /> : <Copy size={16} />}
                    </button>
                  </div>
                </div>

                <form onSubmit={handleEnableTotp}>
                  <div className="form-group">
                    <label className="form-label">Codice di Verifica a 6 Cifre</label>
                    <input
                      type="text"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={6}
                      required
                      placeholder="123456"
                      className="form-control"
                      value={confirmCode}
                      onChange={(e) => setConfirmCode(e.target.value.replace(/\D/g, ''))}
                      style={{
                        fontSize: '1.4rem',
                        fontWeight: 700,
                        letterSpacing: '6px',
                        textAlign: 'center',
                        fontFamily: 'monospace'
                      }}
                    />
                  </div>

                  <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 24 }}>
                    <button type="button" onClick={() => setIs2faModalOpen(false)} className="btn btn-secondary">
                      Annulla
                    </button>
                    <button
                      type="submit"
                      disabled={submitting || confirmCode.length !== 6}
                      className="btn btn-primary"
                    >
                      {submitting ? 'Verifica...' : 'Conferma ed Attiva 2FA'}
                    </button>
                  </div>
                </form>
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
};
