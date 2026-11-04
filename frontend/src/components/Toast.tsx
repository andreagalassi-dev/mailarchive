/**
 * src/client/src/components/Toast.tsx
 * Sistema di notifiche Toast per feedback immediato e accessibile.
 */
import React, { createContext, useContext, useState, useCallback } from 'react';
import { CheckCircle, AlertTriangle, XCircle, Info, X } from 'lucide-react';

export type ToastType = 'success' | 'error' | 'warning' | 'info';

interface ToastItem {
  id: string;
  message: string;
  type: ToastType;
}

interface ToastContextType {
  showToast: (message: string, type?: ToastType) => void;
}

const ToastContext = createContext<ToastContextType>({
  showToast: () => {}
});

export const ToastProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [toasts, setToasts] = useState<ToastItem[]>([]);

  const showToast = useCallback((message: string, type: ToastType = 'info') => {
    const id = Math.random().toString(36).substring(2, 9);
    setToasts((prev) => [...prev, { id, message, type }]);

    setTimeout(() => {
      setToasts((prev) => prev.filter((t) => t.id !== id));
    }, 4500);
  }, []);

  const removeToast = (id: string) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  };

  const getIcon = (type: ToastType) => {
    switch (type) {
      case 'success':
        return <CheckCircle size={18} color="var(--success)" />;
      case 'warning':
        return <AlertTriangle size={18} color="var(--warning)" />;
      case 'error':
        return <XCircle size={18} color="var(--danger)" />;
      default:
        return <Info size={18} color="var(--info)" />;
    }
  };

  return (
    <ToastContext.Provider value={{ showToast }}>
      {children}
      <div
        style={{
          position: 'fixed',
          bottom: 24,
          right: 24,
          display: 'flex',
          flexDirection: 'column',
          gap: 10,
          zIndex: 9999,
          maxWidth: 420,
          width: 'calc(100% - 48px)'
        }}
      >
        {toasts.map((toast) => (
          <div
            key={toast.id}
            className="animate-fade-in card"
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              padding: '12px 16px',
              backgroundColor: 'var(--bg-card)',
              borderLeft: `4px solid ${
                toast.type === 'success'
                  ? 'var(--success)'
                  : toast.type === 'error'
                  ? 'var(--danger)'
                  : toast.type === 'warning'
                  ? 'var(--warning)'
                  : 'var(--info)'
              }`,
              boxShadow: 'var(--shadow-lg)'
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 0 }}>
              {getIcon(toast.type)}
              <span style={{ fontSize: '0.875rem', wordBreak: 'break-word' }}>{toast.message}</span>
            </div>
            <button
              onClick={() => removeToast(toast.id)}
              style={{
                background: 'none',
                border: 'none',
                cursor: 'pointer',
                color: 'var(--text-muted)',
                display: 'flex',
                alignItems: 'center',
                padding: 4
              }}
            >
              <X size={16} />
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
};

export const useToast = () => useContext(ToastContext);
