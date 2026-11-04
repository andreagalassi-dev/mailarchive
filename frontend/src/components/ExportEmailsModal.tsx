/**
 * src/client/src/components/ExportEmailsModal.tsx
 * Modale per l'esportazione massiva e download in blocco delle email archiviate:
 * - Filtri per casella (singola o tutte)
 * - Filtri per cartella (In Arrivo, Inviata o Entrambe)
 * - Filtri per intervallo temporale (Da Data / A Data o tutto lo storico)
 * - Formato: Archivio ZIP (con file .EML organizzati in sottocartelle) o file MBOX
 */
import React, { useState } from 'react';
import {
  Download,
  FolderArchive,
  Calendar,
  Layers,
  Inbox,
  Send,
  HelpCircle,
  FileArchive,
  RefreshCw
} from 'lucide-react';
import { Modal } from './Modal';
import { api } from '../services/api';
import { useToast } from './Toast';

export interface ExportEmailsModalProps {
  isOpen: boolean;
  onClose: () => void;
  mailboxes: any[];
  defaultMailboxId?: string;
}

export const ExportEmailsModal: React.FC<ExportEmailsModalProps> = ({
  isOpen,
  onClose,
  mailboxes,
  defaultMailboxId = 'all'
}) => {
  const { showToast } = useToast();

  const [selectedMailbox, setSelectedMailbox] = useState<string>(defaultMailboxId);
  const [selectedFolder, setSelectedFolder] = useState<'ALL' | 'INBOX' | 'SENT'>('ALL');
  const [useAllHistory, setUseAllHistory] = useState<boolean>(true);
  const [dateFrom, setDateFrom] = useState<string>('');
  const [dateTo, setDateTo] = useState<string>('');
  const [format, setFormat] = useState<'ZIP_EML' | 'MBOX'>('ZIP_EML');
  const [downloading, setDownloading] = useState<boolean>(false);

  // Scorciatoie per le date
  const setQuickRange = (range: 'LAST_30' | 'THIS_YEAR' | 'PREV_YEAR') => {
    setUseAllHistory(false);
    const now = new Date();
    if (range === 'LAST_30') {
      const past = new Date();
      past.setDate(now.getDate() - 30);
      setDateFrom(past.toISOString().split('T')[0]);
      setDateTo(now.toISOString().split('T')[0]);
    } else if (range === 'THIS_YEAR') {
      const year = now.getFullYear();
      setDateFrom(`${year}-01-01`);
      setDateTo(now.toISOString().split('T')[0]);
    } else if (range === 'PREV_YEAR') {
      const year = now.getFullYear() - 1;
      setDateFrom(`${year}-01-01`);
      setDateTo(`${year}-12-31`);
    }
  };

  const handleStartExport = async (e: React.FormEvent) => {
    e.preventDefault();
    setDownloading(true);

    try {
      const params = new URLSearchParams();
      if (selectedMailbox && selectedMailbox !== 'all') {
        params.set('mailboxId', selectedMailbox);
      }
      if (selectedFolder !== 'ALL') {
        params.set('folder', selectedFolder);
      }
      if (!useAllHistory) {
        if (dateFrom) params.set('dateFrom', dateFrom);
        if (dateTo) params.set('dateTo', dateTo);
      }
      params.set('format', format);

      showToast('Preparazione dell’archivio e avvio download in corso...', 'info');

      const downloadUrl = `/api/v1/emails/export?${params.toString()}`;
      const exportFilename = `mailarchive_export_${new Date().toISOString().split('T')[0]}.zip`;
      api.directDownload(downloadUrl, exportFilename);

      showToast('Download dell’archivio avviato nel browser!', 'success');
      onClose();
    } catch (err: any) {
      showToast(err.message || 'Errore durante l’esportazione dei messaggi', 'error');
    } finally {
      setDownloading(false);
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Esportazione Massiva Archivio Email" maxWidth={680}>
      <form onSubmit={handleStartExport} style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
        {/* Box informativo */}
        <div
          style={{
            padding: '12px 14px',
            borderRadius: 8,
            background: 'rgba(37, 99, 235, 0.08)',
            border: '1px solid rgba(37, 99, 235, 0.25)',
            fontSize: '0.825rem',
            color: 'var(--text-secondary)',
            display: 'flex',
            alignItems: 'flex-start',
            gap: 10
          }}
        >
          <FolderArchive size={20} color="var(--accent)" style={{ flexShrink: 0, marginTop: 2 }} />
          <div>
            <strong>Estrazione Dati & Ripristino:</strong> Verrà generato un archivio compresso contenente i messaggi
            originali, ordinati e suddivisi nelle cartelle <strong>Posta_in_Arrivo</strong> e{' '}
            <strong>Posta_Inviata</strong>. I file estratti possono essere trascinati direttamente nei tuoi programmi di
            posta (Outlook, Thunderbird, Apple Mail, Gmail) per ripristinare le email cancellate.
          </div>
        </div>

        {/* Selezione Casella */}
        <div className="form-group">
          <label className="form-label">Quale casella desideri esportare?</label>
          <select
            className="form-control"
            value={selectedMailbox}
            onChange={(e) => setSelectedMailbox(e.target.value)}
          >
            <option value="all">
              🌐 Tutte le caselle (Genera archivio cumulativo con una cartella per casella)
            </option>
            {mailboxes.map((mb) => (
              <option key={mb.id} value={mb.id}>
                📧 {mb.name} ({mb.emailAddress}) — {mb._count?.emails ?? 0} messaggi
              </option>
            ))}
          </select>
        </div>

        {/* Selezione Cartelle (In Arrivo / Inviate) */}
        <div className="form-group">
          <label className="form-label">Quali cartelle includere?</label>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 10 }}>
            <button
              type="button"
              onClick={() => setSelectedFolder('ALL')}
              style={{
                padding: '10px 12px',
                borderRadius: 8,
                border: `2px solid ${selectedFolder === 'ALL' ? 'var(--accent)' : 'var(--border-color)'}`,
                background: selectedFolder === 'ALL' ? 'var(--accent-light)' : 'var(--bg-input)',
                color: selectedFolder === 'ALL' ? 'var(--accent)' : 'var(--text-secondary)',
                fontWeight: 600,
                fontSize: '0.825rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 6,
                cursor: 'pointer'
              }}
            >
              <Layers size={16} />
              <span>Entrambe (Arrivo + Inviate)</span>
            </button>
            <button
              type="button"
              onClick={() => setSelectedFolder('INBOX')}
              style={{
                padding: '10px 12px',
                borderRadius: 8,
                border: `2px solid ${selectedFolder === 'INBOX' ? 'var(--accent)' : 'var(--border-color)'}`,
                background: selectedFolder === 'INBOX' ? 'var(--accent-light)' : 'var(--bg-input)',
                color: selectedFolder === 'INBOX' ? 'var(--accent)' : 'var(--text-secondary)',
                fontWeight: 600,
                fontSize: '0.825rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 6,
                cursor: 'pointer'
              }}
            >
              <Inbox size={16} />
              <span>Solo Posta in Arrivo</span>
            </button>
            <button
              type="button"
              onClick={() => setSelectedFolder('SENT')}
              style={{
                padding: '10px 12px',
                borderRadius: 8,
                border: `2px solid ${selectedFolder === 'SENT' ? 'var(--accent)' : 'var(--border-color)'}`,
                background: selectedFolder === 'SENT' ? 'var(--accent-light)' : 'var(--bg-input)',
                color: selectedFolder === 'SENT' ? 'var(--accent)' : 'var(--text-secondary)',
                fontWeight: 600,
                fontSize: '0.825rem',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 6,
                cursor: 'pointer'
              }}
            >
              <Send size={16} />
              <span>Solo Posta Inviata</span>
            </button>
          </div>
        </div>

        {/* Filtro Temporale (Intervallo Date) */}
        <div
          style={{
            background: 'var(--bg-input)',
            padding: 14,
            borderRadius: 8,
            border: '1px solid var(--border-color)',
            display: 'flex',
            flexDirection: 'column',
            gap: 10
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <label style={{ fontWeight: 600, fontSize: '0.85rem', display: 'flex', alignItems: 'center', gap: 6 }}>
              <Calendar size={15} color="var(--accent)" />
              <span>Intervallo Temporale Date</span>
            </label>
            <label style={{ fontSize: '0.8rem', display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
              <input
                type="checkbox"
                checked={useAllHistory}
                onChange={(e) => setUseAllHistory(e.target.checked)}
              />
              <span>Tutto lo storico disponibile</span>
            </label>
          </div>

          {!useAllHistory && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 4 }}>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <div className="form-group">
                  <label className="form-label" style={{ fontSize: '0.75rem' }}>Da Data (Inizio)</label>
                  <input
                    type="date"
                    required={!useAllHistory}
                    className="form-control"
                    value={dateFrom}
                    onChange={(e) => setDateFrom(e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label className="form-label" style={{ fontSize: '0.75rem' }}>A Data (Fine)</label>
                  <input
                    type="date"
                    required={!useAllHistory}
                    className="form-control"
                    value={dateTo}
                    onChange={(e) => setDateTo(e.target.value)}
                  />
                </div>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>Scorciatoie rapide:</span>
                <button
                  type="button"
                  onClick={() => setQuickRange('LAST_30')}
                  className="btn btn-secondary btn-sm"
                  style={{ fontSize: '0.725rem', padding: '3px 8px' }}
                >
                  Ultimi 30 giorni
                </button>
                <button
                  type="button"
                  onClick={() => setQuickRange('THIS_YEAR')}
                  className="btn btn-secondary btn-sm"
                  style={{ fontSize: '0.725rem', padding: '3px 8px' }}
                >
                  Anno in corso
                </button>
                <button
                  type="button"
                  onClick={() => setQuickRange('PREV_YEAR')}
                  className="btn btn-secondary btn-sm"
                  style={{ fontSize: '0.725rem', padding: '3px 8px' }}
                >
                  Anno precedente
                </button>
              </div>
            </div>
          )}
        </div>

        {/* Formato Archivio */}
        <div className="form-group">
          <label className="form-label">Formato di estrazione</label>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
            <label
              style={{
                display: 'flex',
                alignItems: 'flex-start',
                gap: 8,
                padding: '10px 12px',
                borderRadius: 8,
                border: `2px solid ${format === 'ZIP_EML' ? 'var(--accent)' : 'var(--border-color)'}`,
                background: format === 'ZIP_EML' ? 'var(--accent-light)' : 'var(--bg-input)',
                cursor: 'pointer'
              }}
            >
              <input
                type="radio"
                name="exportFormat"
                value="ZIP_EML"
                checked={format === 'ZIP_EML'}
                onChange={() => setFormat('ZIP_EML')}
                style={{ marginTop: 3 }}
              />
              <div style={{ fontSize: '0.8rem' }}>
                <div style={{ fontWeight: 600, color: 'var(--text-primary)' }}>Archivio ZIP con file .EML</div>
                <div style={{ color: 'var(--text-muted)', fontSize: '0.75rem' }}>
                  Consigliato. File singoli originali con data e oggetto, compatibili con qualsiasi client.
                </div>
              </div>
            </label>
            <label
              style={{
                display: 'flex',
                alignItems: 'flex-start',
                gap: 8,
                padding: '10px 12px',
                borderRadius: 8,
                border: `2px solid ${format === 'MBOX' ? 'var(--accent)' : 'var(--border-color)'}`,
                background: format === 'MBOX' ? 'var(--accent-light)' : 'var(--bg-input)',
                cursor: 'pointer'
              }}
            >
              <input
                type="radio"
                name="exportFormat"
                value="MBOX"
                checked={format === 'MBOX'}
                onChange={() => setFormat('MBOX')}
                style={{ marginTop: 3 }}
              />
              <div style={{ fontSize: '0.8rem' }}>
                <div style={{ fontWeight: 600, color: 'var(--text-primary)' }}>File MBOX (.mbox)</div>
                <div style={{ color: 'var(--text-muted)', fontSize: '0.75rem' }}>
                  Standard UNIX compresso per importazione cumulativa su Thunderbird o Apple Mail.
                </div>
              </div>
            </label>
          </div>
        </div>

        {/* Pulsanti Azione */}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 10 }}>
          <button type="button" onClick={onClose} disabled={downloading} className="btn btn-secondary">
            Annulla
          </button>
          <button
            type="submit"
            disabled={downloading}
            className="btn btn-primary"
            style={{ display: 'flex', alignItems: 'center', gap: 8 }}
          >
            {downloading ? (
              <>
                <RefreshCw size={16} className="animate-spin" />
                <span>Generazione archivio in corso...</span>
              </>
            ) : (
              <>
                <Download size={16} />
                <span>Genera ed Estrai Archivio</span>
              </>
            )}
          </button>
        </div>
      </form>
    </Modal>
  );
};
