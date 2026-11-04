/**
 * src/client/src/App.tsx
 * Router SPA hash-based, provider di contesto (Auth, Theme, Toast) e layout responsive.
 */
import React, { useState, useEffect, Suspense, lazy } from 'react';
import { AuthProvider, useAuth } from './context/AuthContext';
import { ThemeProvider } from './context/ThemeContext';
import { ToastProvider } from './components/Toast';
import { Sidebar } from './components/Sidebar';
import { Header } from './components/Header';
import { ErrorBoundary } from './components/ErrorBoundary';

// Pagine con Code-Splitting (caricamento asincrono on-demand)
const Login = lazy(() => import('./pages/Login').then((m) => ({ default: m.Login })));
const Dashboard = lazy(() => import('./pages/Dashboard').then((m) => ({ default: m.Dashboard })));
const AccountsPage = lazy(() => import('./pages/AccountsPage').then((m) => ({ default: m.AccountsPage })));
const AccessLogsPage = lazy(() => import('./pages/AccessLogsPage').then((m) => ({ default: m.AccessLogsPage })));
const MailPage = lazy(() => import('./pages/MailPage').then((m) => ({ default: m.MailPage })));
const MailboxesPage = lazy(() => import('./pages/MailboxesPage').then((m) => ({ default: m.MailboxesPage })));
const BackupJobsPage = lazy(() => import('./pages/BackupJobsPage').then((m) => ({ default: m.BackupJobsPage })));
const BackupRunsPage = lazy(() => import('./pages/BackupRunsPage').then((m) => ({ default: m.BackupRunsPage })));
const SettingsPage = lazy(() => import('./pages/SettingsPage').then((m) => ({ default: m.SettingsPage })));
const AlertsPage = lazy(() => import('./pages/AlertsPage').then((m) => ({ default: m.AlertsPage })));

const PageLoadingFallback = (
  <div style={{ padding: 48, textAlign: 'center', color: 'var(--text-muted)' }}>
    <div style={{ fontSize: '1.2rem', marginBottom: 8 }}>Caricamento modulo...</div>
  </div>
);

const AppContent: React.FC = () => {
  const { user, loading } = useAuth();
  const [currentRoute, setCurrentRoute] = useState<string>(() => {
    return window.location.hash.replace(/^#\/?/, '') || 'dashboard';
  });

  useEffect(() => {
    const handleHashChange = () => {
      const raw = window.location.hash.replace(/^#\/?/, '');
      setCurrentRoute(raw || 'dashboard');
    };

    window.addEventListener('hashchange', handleHashChange);
    return () => window.removeEventListener('hashchange', handleHashChange);
  }, []);

  // Gestione stato di caricamento iniziale della sessione
  if (loading) {
    return (
      <div
        style={{
          minHeight: '100vh',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          backgroundColor: 'var(--bg-app)',
          color: 'var(--text-secondary)'
        }}
      >
        <div style={{ textAlign: 'center' }}>
          <div style={{ fontSize: '1.1rem', fontWeight: 600, marginBottom: 8 }}>MailArchive</div>
          <div style={{ fontSize: '0.85rem' }}>Verifica sessione in corso...</div>
        </div>
      </div>
    );
  }

  // Utente non autenticato: visualizza solo vista di Login
  if (!user) {
    return (
      <Suspense fallback={PageLoadingFallback}>
        <Login />
      </Suspense>
    );
  }

  // Utente autenticato: se è fermo su #login, reindirizza alla dashboard
  if (currentRoute === 'login') {
    window.location.hash = '#dashboard';
  }

  const isAdmin = user.role === 'ADMIN';

  // Controllo RBAC: le rotte amministrative reindirizzano a dashboard se l'utente è VIEWER
  const adminOnlyRoutes = ['accounts', 'access-logs', 'mailboxes', 'backup-jobs', 'settings'];
  if (adminOnlyRoutes.includes(currentRoute) && !isAdmin) {
    return (
      <div className="app-container">
        <Sidebar currentRoute={currentRoute} />
        <div className="main-content">
          <Header title="Accesso Riservato" />
          <div className="content-body">
            <div className="card" style={{ textAlign: 'center', padding: 48 }}>
              <h3 style={{ color: 'var(--danger)', marginBottom: 8 }}>Accesso Non Autorizzato (403 Forbidden)</h3>
              <p style={{ color: 'var(--text-secondary)' }}>
                Il tuo ruolo attuale (<code>VIEWER</code>) non dispone dei privilegi amministrativi necessari per visualizzare questa sezione.
              </p>
            </div>
          </div>
        </div>
      </div>
    );
  }

  const getPageTitle = (route: string) => {
    switch (route) {
      case 'dashboard': return 'Cruscotto di Controllo';
      case 'accounts': return 'Gestione Account Utenti';
      case 'access-logs': return 'Registri di Accesso e Audit';
      case 'mail': return 'Archivio Messaggi Email';
      case 'mailboxes': return 'Caselle Postali IMAP';
      case 'backup-jobs': return 'Pianificazione Backup Jobs';
      case 'backup-runs': return 'Storico Esecuzioni Backup';
      case 'settings': return 'Impostazioni di Sistema';
      case 'alerts': return 'Allarmi e Segnalazioni';
      default: return 'MailArchive';
    }
  };

  const renderCurrentPage = () => {
    switch (currentRoute) {
      case 'dashboard': return <Dashboard />;
      case 'accounts': return <AccountsPage />;
      case 'access-logs': return <AccessLogsPage />;
      case 'mail': return <MailPage />;
      case 'mailboxes': return <MailboxesPage />;
      case 'backup-jobs': return <BackupJobsPage />;
      case 'backup-runs': return <BackupRunsPage />;
      case 'settings': return <SettingsPage />;
      case 'alerts': return <AlertsPage />;
      default: return <Dashboard />;
    }
  };

  return (
    <div className="app-container">
      <Sidebar currentRoute={currentRoute} />
      <div className="main-content">
        <Header title={getPageTitle(currentRoute)} />
        <ErrorBoundary key={currentRoute}>
          <Suspense fallback={PageLoadingFallback}>
            {renderCurrentPage()}
          </Suspense>
        </ErrorBoundary>
      </div>
    </div>
  );
};

export default function App() {
  return (
    <ThemeProvider>
      <ToastProvider>
        <AuthProvider>
          <AppContent />
        </AuthProvider>
      </ToastProvider>
    </ThemeProvider>
  );
}
