/**
 * src/client/src/pages/Login.tsx
 * Schermata di accesso a due step:
 * Step 1: Inserimento Email e Password.
 * Step 2: Autenticazione a Due Fattori (2FA) con App Authenticator (TOTP) o Codice OTP via Email.
 */
import React, { useState } from 'react';
import {
  Archive,
  Lock,
  Mail,
  AlertCircle,
  ShieldCheck,
  Smartphone,
  ArrowLeft,
  Send,
  CheckCircle2,
  RefreshCw
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';

export const Login: React.FC = () => {
  const { login, verify2fa, send2faEmail } = useAuth();

  // Step 1: Credenziali
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  // Step 2: 2FA State
  const [step, setStep] = useState<'CREDENTIALS' | '2FA'>('CREDENTIALS');
  const [tempToken, setTempToken] = useState('');
  const [emailMasked, setEmailMasked] = useState('');
  const [availableMethods, setAvailableMethods] = useState<string[]>(['EMAIL']);
  const [selectedMethod, setSelectedMethod] = useState<'TOTP' | 'EMAIL'>('EMAIL');
  const [twoFactorCode, setTwoFactorCode] = useState('');
  const [emailSentMessage, setEmailSentMessage] = useState<string | null>(null);
  const [resendCooldown, setResendCooldown] = useState(0);

  // Errori e caricamento
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [sendingEmail, setSendingEmail] = useState(false);

  // Gestione countdown reinvio email
  React.useEffect(() => {
    if (resendCooldown <= 0) return;
    const timer = setTimeout(() => setResendCooldown((c) => c - 1), 1000);
    return () => clearTimeout(timer);
  }, [resendCooldown]);

  // Invio credenziali primarie
  const handleCredentialsSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);

    try {
      const result = await login(email, password);
      if (result.requires2fa) {
        setTempToken(result.tempToken);
        setEmailMasked(result.emailMasked);
        setAvailableMethods(result.methods);
        setSelectedMethod(result.methods.includes('TOTP') ? 'TOTP' : 'EMAIL');
        setStep('2FA');
        // Se l'unico metodo disponibile è EMAIL, inviamo automaticamente il codice
        if (!result.methods.includes('TOTP')) {
          triggerSendEmail(result.tempToken);
        }
      }
    } catch (err: any) {
      setError(err.message || 'Credenziali non valide o accesso non autorizzato.');
    } finally {
      setLoading(false);
    }
  };

  // Invio codice via email
  const triggerSendEmail = async (tokenToUse?: string) => {
    const token = tokenToUse || tempToken;
    if (!token) return;
    setError(null);
    setSendingEmail(true);
    try {
      const msg = await send2faEmail(token);
      setEmailSentMessage(msg);
      setResendCooldown(60); // 60 secondi di attesa prima di poter reinviare
    } catch (err: any) {
      setError(err.message || 'Errore durante l’invio del codice via email.');
    } finally {
      setSendingEmail(false);
    }
  };

  // Verifica codice 2FA (TOTP o Email)
  const handle2faSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);

    try {
      await verify2fa(tempToken, twoFactorCode);
    } catch (err: any) {
      setError(err.message || 'Codice 2FA non valido o scaduto.');
    } finally {
      setLoading(false);
    }
  };

  const handleBackToCredentials = () => {
    setStep('CREDENTIALS');
    setTempToken('');
    setTwoFactorCode('');
    setError(null);
    setEmailSentMessage(null);
  };

  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: 'var(--bg-app)',
        padding: 16
      }}
    >
      <div
        className="card animate-fade-in"
        style={{
          width: '100%',
          maxWidth: 440,
          padding: 32,
          boxShadow: 'var(--shadow-lg)',
          backgroundColor: 'var(--bg-card)'
        }}
      >
        {/* Header Comune */}
        <div style={{ textAlign: 'center', marginBottom: 24 }}>
          <div
            style={{
              width: 52,
              height: 52,
              borderRadius: 12,
              backgroundColor: step === '2FA' ? '#10b981' : 'var(--accent)',
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              color: '#fff',
              marginBottom: 12,
              transition: 'all 0.3s ease'
            }}
          >
            {step === '2FA' ? <ShieldCheck size={30} /> : <Archive size={28} />}
          </div>
          <h2 style={{ fontSize: '1.4rem', fontWeight: 700, color: 'var(--text-primary)' }}>
            {step === '2FA' ? 'Verifica a Due Fattori' : 'MailArchive'}
          </h2>
          <p style={{ fontSize: '0.825rem', color: 'var(--text-secondary)', marginTop: 4 }}>
            {step === '2FA'
              ? `Conferma l'identità per ${emailMasked}`
              : 'Pannello di gestione riservato'}
          </p>
        </div>

        {/* Alert di Errore */}
        {error && (
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 10,
              padding: '10px 14px',
              backgroundColor: 'var(--danger-bg)',
              border: '1px solid var(--danger)',
              borderRadius: 'var(--radius-sm)',
              color: 'var(--danger)',
              fontSize: '0.85rem',
              marginBottom: 20
            }}
          >
            <AlertCircle size={18} style={{ flexShrink: 0 }} />
            <span>{error}</span>
          </div>
        )}

        {/* Notifica Invio Email Riuscito */}
        {emailSentMessage && step === '2FA' && (
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 10,
              padding: '10px 14px',
              backgroundColor: 'rgba(16, 185, 129, 0.12)',
              border: '1px solid #10b981',
              borderRadius: 'var(--radius-sm)',
              color: '#10b981',
              fontSize: '0.85rem',
              marginBottom: 20
            }}
          >
            <CheckCircle2 size={18} style={{ flexShrink: 0 }} />
            <span>{emailSentMessage}</span>
          </div>
        )}

        {/* STEP 1: LOGIN CON EMAIL E PASSWORD */}
        {step === 'CREDENTIALS' && (
          <form onSubmit={handleCredentialsSubmit}>
            <div className="form-group">
              <label className="form-label" htmlFor="login-email">
                Indirizzo Email
              </label>
              <div style={{ position: 'relative' }}>
                <input
                  id="login-email"
                  type="email"
                  required
                  className="form-control"
                  placeholder="nome@azienda.it"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  style={{ paddingLeft: 36 }}
                />
                <Mail
                  size={16}
                  color="var(--text-muted)"
                  style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)' }}
                />
              </div>
            </div>

            <div className="form-group" style={{ marginBottom: 24 }}>
              <label className="form-label" htmlFor="login-password">
                Password
              </label>
              <div style={{ position: 'relative' }}>
                <input
                  id="login-password"
                  type="password"
                  required
                  className="form-control"
                  placeholder="••••••••••••"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  style={{ paddingLeft: 36 }}
                />
                <Lock
                  size={16}
                  color="var(--text-muted)"
                  style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)' }}
                />
              </div>
            </div>

            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading}
              style={{ width: '100%', padding: '10px 16px', fontSize: '0.95rem' }}
            >
              {loading ? 'Verifica credenziali...' : 'Accedi al Sistema'}
            </button>
          </form>
        )}

        {/* STEP 2: VERIFICA A DUE FATTORI (2FA) */}
        {step === '2FA' && (
          <form onSubmit={handle2faSubmit}>
            {/* Selettore Metodo se entrambi disponibili */}
            {availableMethods.includes('TOTP') && (
              <div
                style={{
                  display: 'flex',
                  gap: 8,
                  backgroundColor: 'var(--bg-app)',
                  padding: 4,
                  borderRadius: 'var(--radius-md)',
                  marginBottom: 20
                }}
              >
                <button
                  type="button"
                  onClick={() => setSelectedMethod('TOTP')}
                  className={`btn btn-sm ${selectedMethod === 'TOTP' ? 'btn-primary' : 'btn-secondary'}`}
                  style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}
                >
                  <Smartphone size={14} />
                  <span>App Authenticator</span>
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setSelectedMethod('EMAIL');
                    if (!emailSentMessage && resendCooldown === 0) {
                      triggerSendEmail();
                    }
                  }}
                  className={`btn btn-sm ${selectedMethod === 'EMAIL' ? 'btn-primary' : 'btn-secondary'}`}
                  style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}
                >
                  <Mail size={14} />
                  <span>Codice Email</span>
                </button>
              </div>
            )}

            {/* Informazioni di contesto del metodo selezionato */}
            {selectedMethod === 'TOTP' ? (
              <div
                style={{
                  padding: '12px 14px',
                  backgroundColor: 'rgba(59, 130, 246, 0.08)',
                  borderRadius: 'var(--radius-sm)',
                  fontSize: '0.825rem',
                  color: 'var(--text-secondary)',
                  marginBottom: 20,
                  display: 'flex',
                  alignItems: 'flex-start',
                  gap: 10
                }}
              >
                <Smartphone size={18} color="var(--accent)" style={{ flexShrink: 0, marginTop: 2 }} />
                <span>
                  Apri la tua applicazione <strong>Authenticator</strong> (Google Authenticator, Microsoft Auth,
                  1Password) e inserisci il codice a 6 cifre visualizzato.
                </span>
              </div>
            ) : (
              <div
                style={{
                  padding: '12px 14px',
                  backgroundColor: 'rgba(16, 185, 129, 0.08)',
                  borderRadius: 'var(--radius-sm)',
                  fontSize: '0.825rem',
                  color: 'var(--text-secondary)',
                  marginBottom: 20
                }}
              >
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10, marginBottom: 8 }}>
                  <Mail size={18} color="#10b981" style={{ flexShrink: 0, marginTop: 2 }} />
                  <span>
                    È stato generato un codice monouso valido 10 minuti per la tua casella <strong>{emailMasked}</strong>.
                  </span>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <button
                    type="button"
                    onClick={() => triggerSendEmail()}
                    disabled={sendingEmail || resendCooldown > 0}
                    className="btn btn-secondary btn-sm"
                    style={{ fontSize: '0.75rem', padding: '4px 8px' }}
                  >
                    {sendingEmail ? (
                      <RefreshCw size={12} className="animate-spin" />
                    ) : (
                      <Send size={12} />
                    )}
                    <span>
                      {resendCooldown > 0
                        ? `Reinvia tra ${resendCooldown}s`
                        : 'Reinvia codice via email'}
                    </span>
                  </button>
                </div>
              </div>
            )}

            {/* Input Codice OTP a 6 cifre */}
            <div className="form-group" style={{ marginBottom: 24, textAlign: 'center' }}>
              <label
                className="form-label"
                htmlFor="two-factor-code"
                style={{ display: 'block', marginBottom: 8, fontSize: '0.875rem' }}
              >
                Codice di Verifica a 6 Cifre
              </label>
              <input
                id="two-factor-code"
                type="text"
                inputMode="numeric"
                pattern="[0-9]*"
                maxLength={6}
                autoComplete="one-time-code"
                autoFocus
                required
                className="form-control"
                placeholder="123456"
                value={twoFactorCode}
                onChange={(e) => {
                  const val = e.target.value.replace(/\D/g, '');
                  setTwoFactorCode(val);
                }}
                style={{
                  fontSize: '1.75rem',
                  fontWeight: 700,
                  letterSpacing: '8px',
                  textAlign: 'center',
                  fontFamily: 'monospace',
                  padding: '10px 14px'
                }}
              />
            </div>

            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading || twoFactorCode.length !== 6}
              style={{ width: '100%', padding: '10px 16px', fontSize: '0.95rem', marginBottom: 12 }}
            >
              {loading ? 'Verifica in corso...' : 'Verifica ed Accedi'}
            </button>

            <button
              type="button"
              onClick={handleBackToCredentials}
              className="btn btn-secondary"
              style={{
                width: '100%',
                padding: '8px 14px',
                fontSize: '0.85rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 6
              }}
            >
              <ArrowLeft size={14} />
              <span>Torna all'accesso con password</span>
            </button>
          </form>
        )}

        <div
          style={{
            marginTop: 24,
            textAlign: 'center',
            fontSize: '0.75rem',
            color: 'var(--text-muted)',
            borderTop: '1px solid var(--border-color)',
            paddingTop: 16
          }}
        >
          🔒 Connessione cifrata TLS con crittografia end-to-end e cookie HttpOnly
        </div>
      </div>
    </div>
  );
};
