/**
 * src/client/src/components/Header.tsx
 * Barra superiore con titolo vista, contatore allarmi e toggle tema Dark/Light.
 */
import React from 'react';
import { Moon, Sun } from 'lucide-react';
import { useTheme } from '../context/ThemeContext';
import { useAuth } from '../context/AuthContext';

interface HeaderProps {
  title: string;
}

export const Header: React.FC<HeaderProps> = ({ title }) => {
  const { theme, toggleTheme } = useTheme();
  const { user } = useAuth();

  return (
    <header
      style={{
        height: 64,
        borderBottom: '1px solid var(--border-color)',
        backgroundColor: 'var(--bg-header)',
        backdropFilter: 'blur(8px)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        padding: '0 24px',
        position: 'sticky',
        top: 0,
        zIndex: 50
      }}
    >
      <h1 style={{ fontSize: '1.25rem', fontWeight: 600, color: 'var(--text-primary)' }}>
        {title}
      </h1>

      <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
        {/* Toggle Dark/Light Mode */}
        <button
          onClick={toggleTheme}
          className="btn btn-secondary btn-sm"
          title={`Attiva tema ${theme === 'dark' ? 'chiaro' : 'scuro'}`}
          style={{ padding: '6px 10px' }}
        >
          {theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}
          <span style={{ fontSize: '0.8rem' }}>{theme === 'dark' ? 'Chiaro' : 'Scuro'}</span>
        </button>

        {/* User Pill */}
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '4px 10px',
            backgroundColor: 'var(--bg-card)',
            border: '1px solid var(--border-color)',
            borderRadius: 9999,
            fontSize: '0.8rem'
          }}
        >
          <span style={{ color: 'var(--text-secondary)' }}>{user?.email}</span>
          <span className="badge badge-info" style={{ fontSize: '0.65rem' }}>{user?.role}</span>
        </div>
      </div>
    </header>
  );
};
