/**
 * src/client/src/context/AuthContext.tsx
 * Gestione dello stato di autenticazione, sessione HttpOnly e ruolo RBAC.
 */
import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { api, fetchCsrfToken } from '../services/api';

export interface UserProfile {
  id: string;
  email: string;
  role: 'ADMIN' | 'VIEWER';
  isActive: boolean;
}

export type LoginResult =
  | { requires2fa: false; user: UserProfile }
  | { requires2fa: true; tempToken: string; emailMasked: string; methods: string[] };

interface AuthContextType {
  user: UserProfile | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<LoginResult>;
  verify2fa: (tempToken: string, code: string) => Promise<void>;
  send2faEmail: (tempToken: string) => Promise<string>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType>({
  user: null,
  loading: true,
  login: async () => ({ requires2fa: false, user: { id: '', email: '', role: 'VIEWER', isActive: false } }),
  verify2fa: async () => {},
  send2faEmail: async () => '',
  logout: async () => {},
  refreshUser: async () => {}
});

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<UserProfile | null>(null);
  const [loading, setLoading] = useState<boolean>(true);

  const refreshUser = useCallback(async () => {
    try {
      await fetchCsrfToken();
      const res = await api.get<{ status: string; user: UserProfile }>('/api/v1/auth/me');
      if (res && res.user) {
        setUser(res.user);
      } else {
        setUser(null);
      }
    } catch {
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refreshUser();

    // Ascolto evento sessione scaduta inviato da api.ts
    const handleUnauthorized = () => {
      setUser(null);
      window.location.hash = '#login';
    };

    window.addEventListener('auth:unauthorized', handleUnauthorized);
    return () => {
      window.removeEventListener('auth:unauthorized', handleUnauthorized);
    };
  }, [refreshUser]);

  const login = async (email: string, password: string): Promise<LoginResult> => {
    await fetchCsrfToken();
    const res = await api.post<any>('/api/v1/auth/login', {
      email,
      password
    });

    if (res && res.status === 'requires_2fa') {
      return {
        requires2fa: true,
        tempToken: res.tempToken,
        emailMasked: res.emailMasked,
        methods: res.methods || ['EMAIL']
      };
    }

    if (res && res.user) {
      setUser(res.user);
      window.location.hash = '#dashboard';
      return { requires2fa: false, user: res.user };
    }

    throw new Error('Risposta di autenticazione imprevista.');
  };

  const verify2fa = async (tempToken: string, code: string): Promise<void> => {
    await fetchCsrfToken();
    const res = await api.post<{ status: string; user: UserProfile }>('/api/v1/auth/2fa/verify', {
      tempToken,
      code
    });
    if (res && res.user) {
      setUser(res.user);
      window.location.hash = '#dashboard';
    }
  };

  const send2faEmail = async (tempToken: string): Promise<string> => {
    await fetchCsrfToken();
    const res = await api.post<{ status: string; message: string }>('/api/v1/auth/2fa/send-email', {
      tempToken
    });
    return res.message || 'Codice inviato via email';
  };

  const logout = async (): Promise<void> => {
    try {
      await api.post('/api/v1/auth/logout');
    } catch {
      // Ignora errori di rete su logout
    } finally {
      setUser(null);
      window.location.hash = '#login';
    }
  };

  return (
    <AuthContext.Provider value={{ user, loading, login, verify2fa, send2faEmail, logout, refreshUser }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => useContext(AuthContext);
