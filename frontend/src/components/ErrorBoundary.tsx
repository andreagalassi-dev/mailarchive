/**
 * src/client/src/components/ErrorBoundary.tsx
 * Intercetta gli errori di rendering e di caricamento delle pagine (chunk lazy)
 * mostrando un messaggio al posto di una schermata vuota.
 */
import React from 'react';

interface ErrorBoundaryProps {
  children: React.ReactNode;
}

interface ErrorBoundaryState {
  error: Error | null;
}

export class ErrorBoundary extends React.Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { error: null };

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    console.error('Errore nella pagina:', error, info.componentStack);
  }

  render() {
    if (!this.state.error) {
      return this.props.children;
    }

    return (
      <div className="content-body">
        <div className="card" style={{ textAlign: 'center', padding: 48 }}>
          <h3 style={{ color: 'var(--danger)', marginBottom: 8 }}>Impossibile caricare questa pagina</h3>
          <p style={{ color: 'var(--text-secondary)', marginBottom: 16 }}>
            {this.state.error.message || 'Errore imprevisto.'} Prova a ricaricare; se persiste, svuota la cache del browser.
          </p>
          <button className="btn btn-secondary" onClick={() => window.location.reload()}>
            Ricarica la pagina
          </button>
        </div>
      </div>
    );
  }
}
