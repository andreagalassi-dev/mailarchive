/**
 * src/client/src/components/Sidebar.tsx
 * Menu laterale con navigazione ad hash, icone Lucide e filtro visibilità RBAC.
 */
import React from 'react';
import {
  LayoutDashboard,
  Users,
  ShieldCheck,
  Mail,
  Server,
  Clock,
  History,
  Settings,
  Bell,
  LogOut,
  Archive
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';

interface SidebarProps {
  currentRoute: string;
}

interface NavItem {
  key: string;
  label: string;
  icon: React.ReactNode;
  adminOnly?: boolean;
}

export const Sidebar: React.FC<SidebarProps> = ({ currentRoute }) => {
  const { user, logout } = useAuth();
  const isAdmin = user?.role === 'ADMIN';

  const navItems: NavItem[] = [
    { key: 'dashboard', label: 'Dashboard', icon: <LayoutDashboard size={18} /> },
    { key: 'accounts', label: 'Account', icon: <Users size={18} />, adminOnly: true },
    { key: 'access-logs', label: 'Log Accessi', icon: <ShieldCheck size={18} />, adminOnly: true },
    { key: 'mail', label: 'Email Archiviate', icon: <Mail size={18} /> },
    { key: 'mailboxes', label: 'Caselle IMAP', icon: <Server size={18} />, adminOnly: true },
    { key: 'backup-jobs', label: 'Backup Jobs', icon: <Clock size={18} />, adminOnly: true },
    { key: 'backup-runs', label: 'Storico Backup', icon: <History size={18} /> },
    { key: 'settings', label: 'Impostazioni', icon: <Settings size={18} />, adminOnly: true },
    { key: 'alerts', label: 'Allarmi & Avvisi', icon: <Bell size={18} /> }
  ];

  const visibleItems = navItems.filter((item) => !item.adminOnly || isAdmin);

  return (
    <aside
      style={{
        width: 260,
        backgroundColor: 'var(--bg-sidebar)',
        borderRight: '1px solid var(--border-color)',
        display: 'flex',
        flexDirection: 'column',
        flexShrink: 0
      }}
    >
      {/* Brand Header */}
      <div
        style={{
          padding: '24px 20px',
          borderBottom: '1px solid var(--border-color)',
          display: 'flex',
          alignItems: 'center',
          gap: 12
        }}
      >
        <div
          style={{
            width: 36,
            height: 36,
            borderRadius: 8,
            backgroundColor: 'var(--accent)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: '#fff'
          }}
        >
          <Archive size={20} />
        </div>
        <div style={{ minWidth: 0 }}>
          <div style={{ fontWeight: 700, fontSize: '1.05rem', color: 'var(--text-primary)', letterSpacing: '-0.01em' }}>
            MailArchive
          </div>
          <div style={{ fontSize: '0.725rem', color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
            Archivio email privato
          </div>
        </div>
      </div>

      {/* Nav Menu */}
      <nav style={{ flex: 1, padding: '16px 12px', display: 'flex', flexDirection: 'column', gap: 4, overflowY: 'auto' }}>
        {visibleItems.map((item) => {
          const isActive = currentRoute === item.key;
          return (
            <a
              key={item.key}
              href={`#${item.key}`}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 12,
                padding: '10px 14px',
                borderRadius: 'var(--radius-sm)',
                fontSize: '0.875rem',
                fontWeight: isActive ? 600 : 500,
                color: isActive ? '#ffffff' : 'var(--text-secondary)',
                backgroundColor: isActive ? 'var(--accent)' : 'transparent',
                textDecoration: 'none',
                transition: 'background-color 0.15s, color 0.15s'
              }}
              onMouseEnter={(e) => {
                if (!isActive) {
                  e.currentTarget.style.backgroundColor = 'var(--bg-card-hover)';
                  e.currentTarget.style.color = 'var(--text-primary)';
                }
              }}
              onMouseLeave={(e) => {
                if (!isActive) {
                  e.currentTarget.style.backgroundColor = 'transparent';
                  e.currentTarget.style.color = 'var(--text-secondary)';
                }
              }}
            >
              {item.icon}
              <span>{item.label}</span>
            </a>
          );
        })}
      </nav>

      {/* User Info & Logout Footer */}
      <div
        style={{
          padding: '16px',
          borderTop: '1px solid var(--border-color)',
          display: 'flex',
          flexDirection: 'column',
          gap: 12
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: '0.85rem', fontWeight: 600, color: 'var(--text-primary)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {user?.email}
            </div>
            <div style={{ fontSize: '0.725rem', color: 'var(--text-muted)' }}>
              Ruolo: <span style={{ fontWeight: 600, color: user?.role === 'ADMIN' ? 'var(--info)' : 'var(--text-secondary)' }}>{user?.role}</span>
            </div>
          </div>
        </div>

        <button
          onClick={logout}
          className="btn btn-secondary btn-sm"
          style={{ width: '100%', justifyContent: 'center' }}
        >
          <LogOut size={16} />
          <span>Disconnetti</span>
        </button>
      </div>
    </aside>
  );
};
