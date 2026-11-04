/**
 * src/client/src/pages/MailPage.tsx
 * Consultazione ed esplorazione delle email archiviate con layout a due colonne:
 * - Colonna Sinistra: Navigazione gerarchica per casella e per cartella (In Arrivo / Inviate)
 * - Colonna Destra: Filtri di ricerca avanzati, visualizzazione messaggi e download .EML
 * - Visualizzatore HTML sicuro in iframe sandboxed anti-XSS
 */
import React, { useEffect, useState, useCallback } from 'react';
import {
  Search,
  Eye,
  Download,
  Paperclip,
  AlertTriangle,
  ShieldAlert,
  RefreshCw,
  FolderArchive,
  FolderTree,
  Inbox,
  Send,
  Layers,
  Mail,
  CheckCircle2
} from 'lucide-react';
import { api } from '../services/api';
import { useToast } from '../components/Toast';
import { useTheme } from '../context/ThemeContext';
import { DataTable, Column } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { SanitizedHtmlViewer } from '../components/SanitizedHtmlViewer';
import { ExportEmailsModal } from '../components/ExportEmailsModal';

export const MailPage: React.FC = () => {
  const { showToast } = useToast();
  const { theme } = useTheme();

  const [emails, setEmails] = useState<any[]>([]);
  const [mailboxes, setMailboxes] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);

  // Selezione navigazione casella e cartella
  const [selectedMailbox, setSelectedMailbox] = useState<string>('ALL');
  const [selectedFolder, setSelectedFolder] = useState<'ALL' | 'INBOX' | 'SENT'>('ALL');

  // Filtri di ricerca
  const [searchFrom, setSearchFrom] = useState('');
  const [searchSubject, setSearchSubject] = useState('');
  const [filterSpam, setFilterSpam] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalCount, setTotalCount] = useState(0);
  const [exportModalOpen, setExportModalOpen] = useState(false);

  // Dettaglio email selezionata
  const [selectedEmail, setSelectedEmail] = useState<any | null>(null);
  const [emailDetailLoading, setEmailDetailLoading] = useState(false);

  // Caricamento elenco caselle per navigatore laterale
  useEffect(() => {
    api.get('/api/v1/mailboxes')
      .then((res) => setMailboxes(res.data || []))
      .catch(() => {});
  }, []);

  const loadEmails = useCallback(async (targetPage: number) => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      params.set('page', String(targetPage));
      params.set('limit', '20');
      if (searchFrom) params.set('fromAddress', searchFrom);
      if (searchSubject) params.set('subject', searchSubject);
      if (selectedMailbox && selectedMailbox !== 'ALL') params.set('mailboxId', selectedMailbox);
      if (selectedFolder && selectedFolder !== 'ALL') params.set('folder', selectedFolder);
      if (filterSpam !== '') params.set('isSpam', filterSpam);

      const res = await api.get(`/api/v1/emails?${params.toString()}`);
      setEmails(res.data || []);
      setPage(res.pagination?.page || 1);
      setTotalPages(res.pagination?.totalPages || 1);
      setTotalCount(res.pagination?.totalCount || 0);
    } catch (err: any) {
      showToast(err.message || 'Errore nel caricamento delle email', 'error');
    } finally {
      setLoading(false);
    }
  }, [searchFrom, searchSubject, selectedMailbox, selectedFolder, filterSpam, showToast]);

  useEffect(() => {
    loadEmails(page);
  }, [page, loadEmails]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    loadEmails(1);
  };

  const handleSelectFolder = (mailboxId: string, folder: 'ALL' | 'INBOX' | 'SENT') => {
    setSelectedMailbox(mailboxId);
    setSelectedFolder(folder);
    setPage(1);
  };

  const handleOpenDetail = async (emailId: string) => {
    setEmailDetailLoading(true);
    try {
      const res = await api.get(`/api/v1/emails/${emailId}`);
      setSelectedEmail(res.data);
    } catch (err: any) {
      showToast(err.message || 'Impossibile recuperare il dettaglio email', 'error');
    } finally {
      setEmailDetailLoading(false);
    }
  };

  const handleDownloadAttachment = (attId: string, filename: string) => {
    try {
      api.directDownload(`/api/v1/attachments/${attId}/download`, filename);
      showToast(`Download avviato: ${filename}`, 'info');
    } catch (err: any) {
      showToast(err.message || 'Errore durante il download dell’allegato', 'error');
    }
  };

  const handleDownloadEml = (emailId: string, subject: string) => {
    try {
      const safeName = (subject || 'messaggio').replace(/[^a-zA-Z0-9._-]/g, '_') + '.eml';
      api.directDownload(`/api/v1/emails/${emailId}/download-eml`, safeName);
      showToast(`Download file originale .EML avviato (${safeName})`, 'info');
    } catch (err: any) {
      showToast(err.message || 'Errore durante il download del file .EML', 'error');
    }
  };

  const columns: Column<any>[] = [
    {
      key: 'folder',
      label: 'Cartella',
      render: (m) => (
        m.folder === 'SENT' ? (
          <span
            className="badge"
            style={{
              background: 'rgba(34, 197, 94, 0.12)',
              color: '#16a34a',
              border: '1px solid rgba(34, 197, 94, 0.3)',
              display: 'inline-flex',
              alignItems: 'center',
              gap: 4,
              fontSize: '0.725rem',
              fontWeight: 600
            }}
          >
            <Send size={11} />
            <span>Inviata</span>
          </span>
        ) : (
          <span
            className="badge"
            style={{
              background: 'rgba(37, 99, 235, 0.12)',
              color: 'var(--accent)',
              border: '1px solid rgba(37, 99, 235, 0.3)',
              display: 'inline-flex',
              alignItems: 'center',
              gap: 4,
              fontSize: '0.725rem',
              fontWeight: 600
            }}
          >
            <Inbox size={11} />
            <span>In Arrivo</span>
          </span>
        )
      ),
      width: 110
    },
    {
      key: 'dateSent',
      label: 'Data Invio',
      render: (m) => (
        <span style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
          {new Date(m.dateSent).toLocaleString()}
        </span>
      ),
      width: 155
    },
    {
      key: 'fromAddress',
      label: 'Mittente',
      render: (m) => (
        <div style={{ overflow: 'hidden' }}>
          <div style={{ fontWeight: 600, fontSize: '0.825rem', whiteSpace: 'nowrap', textOverflow: 'ellipsis', overflow: 'hidden' }}>
            {m.fromName || m.fromAddress}
          </div>
          {m.fromName && (
            <div style={{ fontSize: '0.725rem', color: 'var(--text-muted)', whiteSpace: 'nowrap', textOverflow: 'ellipsis', overflow: 'hidden' }}>
              {m.fromAddress}
            </div>
          )}
        </div>
      ),
      width: 210
    },
    {
      key: 'subject',
      label: 'Oggetto',
      render: (m) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ fontWeight: 500, fontSize: '0.825rem' }}>{m.subject}</span>
          {m.hasAttachments && <span title="Contiene allegati" style={{ display: 'inline-flex', flexShrink: 0 }}><Paperclip size={13} color="var(--info)" /></span>}
          {m.isSpam && <span className="badge badge-danger" style={{ fontSize: '0.65rem' }}>SPAM</span>}
          {m.isFlagged && <span className="badge badge-warning" style={{ fontSize: '0.65rem' }}>FLAG</span>}
        </div>
      )
    },
    {
      key: 'mailbox',
      label: 'Casella',
      render: (m) => (
        <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
          {m.mailbox?.emailAddress || m.mailbox?.name || '-'}
        </span>
      ),
      width: 160
    },
    {
      key: 'actions',
      label: 'Azioni',
      render: (m) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <button
            onClick={() => handleOpenDetail(m.id)}
            className="btn btn-secondary btn-sm"
            title="Visualizza Dettaglio Messaggio"
          >
            <Eye size={14} />
            <span>Apri</span>
          </button>
          <button
            onClick={() => handleDownloadEml(m.id, m.subject)}
            className="btn btn-secondary btn-sm"
            title="Scarica file originale .EML (per ripristino in Outlook/Thunderbird/Gmail)"
          >
            <Download size={14} />
          </button>
        </div>
      ),
      width: 130
    }
  ];

  // Stile pulsanti di navigazione
  const getNavButtonStyle = (isActive: boolean) => ({
    display: 'flex',
    alignItems: 'center',
    gap: 8,
    width: '100%',
    padding: '7px 10px',
    borderRadius: 6,
    border: 'none',
    background: isActive ? 'var(--accent)' : 'transparent',
    color: isActive ? '#fff' : 'var(--text-secondary)',
    fontWeight: isActive ? 600 : 400,
    fontSize: '0.8rem',
    cursor: 'pointer',
    textAlign: 'left' as const,
    transition: 'all 0.15s ease'
  });

  const getSubNavButtonStyle = (isActive: boolean) => ({
    display: 'flex',
    alignItems: 'center',
    gap: 7,
    width: '100%',
    padding: '5px 8px',
    borderRadius: 5,
    border: 'none',
    background: isActive ? 'var(--accent)' : 'transparent',
    color: isActive ? '#fff' : 'var(--text-secondary)',
    fontWeight: isActive ? 600 : 400,
    fontSize: '0.75rem',
    cursor: 'pointer',
    textAlign: 'left' as const,
    transition: 'all 0.15s ease'
  });

  const activeMailboxName =
    selectedMailbox === 'ALL'
      ? '🌐 Tutte le Caselle'
      : mailboxes.find((m) => m.id === selectedMailbox)?.emailAddress || 'Casella Selezionata';

  const activeFolderName =
    selectedFolder === 'ALL'
      ? '📁 Tutte le Cartelle'
      : selectedFolder === 'INBOX'
      ? '📥 Posta in Arrivo (Entrata)'
      : '📤 Posta Inviata (Uscita)';

  return (
    <div className="content-body animate-fade-in" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      {/* Header Pagina */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2 style={{ fontSize: '1.25rem', fontWeight: 600, color: 'var(--text-primary)' }}>Email Archiviate</h2>
          <p style={{ fontSize: '0.825rem', color: 'var(--text-secondary)' }}>
            Esplorazione per casella e cartella, ricerca e download messaggi ({totalCount.toLocaleString()} email totali)
          </p>
        </div>
        <button
          type="button"
          onClick={() => setExportModalOpen(true)}
          className="btn btn-secondary"
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
          title="Scarica tutte le email in un unico file compresso ZIP o MBOX"
        >
          <FolderArchive size={16} color="var(--accent)" />
          <span>Esporta / Backup in Blocco</span>
        </button>
      </div>

      {/* Griglia a 2 Colonne: Navigatore Caselle/Cartelle a sinistra + Contenuto a destra */}
      <div style={{ display: 'grid', gridTemplateColumns: '290px 1fr', gap: 16, alignItems: 'start' }}>
        {/* ========================================================= */}
        {/* COLONNA SINISTRA: SELEZIONE CASELLE E CARTELLE              */}
        {/* ========================================================= */}
        <div
          className="card"
          style={{
            padding: 12,
            display: 'flex',
            flexDirection: 'column',
            gap: 14,
            position: 'sticky',
            top: 20
          }}
        >
          {/* Header Barra Laterale */}
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              borderBottom: '1px solid var(--border-color)',
              paddingBottom: 8
            }}
          >
            <div style={{ fontWeight: 600, fontSize: '0.825rem', color: 'var(--text-primary)', display: 'flex', alignItems: 'center', gap: 6 }}>
              <FolderTree size={16} color="var(--accent)" />
              <span>Cartelle & Caselle</span>
            </div>
            <span style={{ fontSize: '0.7rem', padding: '2px 6px', borderRadius: 4, background: 'var(--bg-app)', color: 'var(--text-muted)' }}>
              {mailboxes.length} caselle
            </span>
          </div>

          {/* Sezione 1: Tutte le Caselle */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <div style={{ fontSize: '0.675rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px', marginBottom: 3 }}>
              🌐 Tutte le Caselle
            </div>
            <button
              type="button"
              onClick={() => handleSelectFolder('ALL', 'ALL')}
              style={getNavButtonStyle(selectedMailbox === 'ALL' && selectedFolder === 'ALL')}
            >
              <Layers size={14} />
              <span style={{ flex: 1 }}>Tutti i Messaggi</span>
            </button>
            <button
              type="button"
              onClick={() => handleSelectFolder('ALL', 'INBOX')}
              style={getNavButtonStyle(selectedMailbox === 'ALL' && selectedFolder === 'INBOX')}
            >
              <Inbox size={14} />
              <span style={{ flex: 1 }}>Posta in Arrivo (Entrata)</span>
            </button>
            <button
              type="button"
              onClick={() => handleSelectFolder('ALL', 'SENT')}
              style={getNavButtonStyle(selectedMailbox === 'ALL' && selectedFolder === 'SENT')}
            >
              <Send size={14} />
              <span style={{ flex: 1 }}>Posta Inviata (Uscita)</span>
            </button>
          </div>

          {/* Divisore */}
          <div style={{ height: 1, background: 'var(--border-color)' }} />

          {/* Sezione 2: Singole Caselle */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            <div style={{ fontSize: '0.675rem', fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
              📧 Caselle Email
            </div>

            {mailboxes.length === 0 ? (
              <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', padding: '6px 0' }}>
                Nessuna casella configurata
              </div>
            ) : (
              mailboxes.map((mb) => {
                const isSelected = selectedMailbox === mb.id;
                return (
                  <div
                    key={mb.id}
                    style={{
                      borderRadius: 8,
                      border: `1px solid ${isSelected ? 'var(--accent)' : 'var(--border-color)'}`,
                      background: isSelected ? 'rgba(37, 99, 235, 0.04)' : 'var(--bg-app)',
                      padding: 6,
                      display: 'flex',
                      flexDirection: 'column',
                      gap: 3
                    }}
                  >
                    {/* Intestazione Singola Casella */}
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '2px 4px' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6, overflow: 'hidden' }}>
                        <Mail size={13} color="var(--accent)" style={{ flexShrink: 0 }} />
                        <strong
                          style={{
                            fontSize: '0.775rem',
                            color: 'var(--text-primary)',
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis'
                          }}
                          title={mb.emailAddress}
                        >
                          {mb.name || mb.emailAddress}
                        </strong>
                      </div>
                      <span
                        style={{
                          fontSize: '0.675rem',
                          padding: '1px 5px',
                          borderRadius: 4,
                          background: 'var(--bg-card)',
                          color: 'var(--text-muted)',
                          border: '1px solid var(--border-color)',
                          flexShrink: 0
                        }}
                      >
                        {mb._count?.emails ?? 0}
                      </span>
                    </div>
                    <div
                      style={{
                        fontSize: '0.675rem',
                        color: 'var(--text-muted)',
                        paddingLeft: 18,
                        marginBottom: 3,
                        whiteSpace: 'nowrap',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis'
                      }}
                      title={mb.emailAddress}
                    >
                      {mb.emailAddress}
                    </div>

                    {/* Sottocartelle Casella */}
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 2, paddingLeft: 6 }}>
                      <button
                        type="button"
                        onClick={() => handleSelectFolder(mb.id, 'ALL')}
                        style={getSubNavButtonStyle(isSelected && selectedFolder === 'ALL')}
                      >
                        <Layers size={11} />
                        <span style={{ flex: 1 }}>Tutte le cartelle</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => handleSelectFolder(mb.id, 'INBOX')}
                        style={getSubNavButtonStyle(isSelected && selectedFolder === 'INBOX')}
                      >
                        <Inbox size={11} color={isSelected && selectedFolder === 'INBOX' ? '#fff' : 'var(--info)'} />
                        <span style={{ flex: 1 }}>Posta in Arrivo</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => handleSelectFolder(mb.id, 'SENT')}
                        style={getSubNavButtonStyle(isSelected && selectedFolder === 'SENT')}
                      >
                        <Send size={11} color={isSelected && selectedFolder === 'SENT' ? '#fff' : 'var(--success)'} />
                        <span style={{ flex: 1 }}>Posta Inviata</span>
                      </button>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>

        {/* ========================================================= */}
        {/* COLONNA DESTRA: FILTRI, TABELLA RISULTATI E DETTAGLIO       */}
        {/* ========================================================= */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14, minWidth: 0 }}>
          {/* Barra Navigazione Attiva (Breadcrumbs) */}
          <div
            className="card"
            style={{
              padding: '10px 14px',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: 12,
              background: 'rgba(37, 99, 235, 0.05)',
              borderColor: 'rgba(37, 99, 235, 0.2)'
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: '0.825rem' }}>
              <span style={{ color: 'var(--text-muted)' }}>Visualizzazione:</span>
              <strong style={{ color: 'var(--text-primary)' }}>{activeMailboxName}</strong>
              <span style={{ color: 'var(--text-muted)' }}>›</span>
              <span style={{ color: 'var(--accent)', fontWeight: 600 }}>{activeFolderName}</span>
            </div>
            <span style={{ fontSize: '0.775rem', color: 'var(--text-muted)' }}>
              {totalCount.toLocaleString()} email visualizzate
            </span>
          </div>

          {/* Form Filtri di Ricerca compatto */}
          <div className="card" style={{ padding: 12 }}>
            <form
              onSubmit={handleSearchSubmit}
              style={{
                display: 'grid',
                gridTemplateColumns: '1fr 1fr 140px 90px',
                gap: 10,
                alignItems: 'flex-end'
              }}
            >
              <div>
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Filtra Mittente</label>
                <input
                  type="text"
                  placeholder="Cerca per mittente..."
                  className="form-control"
                  value={searchFrom}
                  onChange={(e) => setSearchFrom(e.target.value)}
                />
              </div>

              <div>
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Filtra Oggetto</label>
                <input
                  type="text"
                  placeholder="Cerca per oggetto..."
                  className="form-control"
                  value={searchSubject}
                  onChange={(e) => setSearchSubject(e.target.value)}
                />
              </div>

              <div>
                <label className="form-label" style={{ fontSize: '0.75rem' }}>Filtro Spam</label>
                <select
                  className="form-control"
                  value={filterSpam}
                  onChange={(e) => setFilterSpam(e.target.value)}
                >
                  <option value="">Tutti</option>
                  <option value="false">Solo normali</option>
                  <option value="true">Solo spam</option>
                </select>
              </div>

              <button type="submit" className="btn btn-primary" style={{ height: 38 }}>
                <Search size={15} />
                <span>Cerca</span>
              </button>
            </form>
          </div>

          {/* Tabella Risultati */}
          <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
            <DataTable
              columns={columns}
              data={emails}
              loading={loading}
              emptyMessage={`Nessun messaggio trovato in ${activeFolderName} per ${activeMailboxName}`}
              pagination={{
                page,
                totalPages,
                totalCount,
                onPageChange: (p) => setPage(p)
              }}
            />
          </div>
        </div>
      </div>

      {/* Modal Dettaglio Email */}
      <Modal
        isOpen={Boolean(selectedEmail) || emailDetailLoading}
        onClose={() => setSelectedEmail(null)}
        title={selectedEmail?.subject || 'Dettaglio Messaggio'}
        maxWidth={900}
      >
        {emailDetailLoading ? (
          <div style={{ textAlign: 'center', padding: '40px 0', color: 'var(--text-muted)' }}>
            Caricamento messaggio e sanitizzazione in corso...
          </div>
        ) : selectedEmail ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            {/* Header Metadati Email */}
            <div
              style={{
                backgroundColor: 'var(--bg-app)',
                padding: 16,
                borderRadius: 'var(--radius-sm)',
                border: '1px solid var(--border-color)',
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                gap: 10,
                fontSize: '0.85rem'
              }}
            >
              <div>
                <span style={{ color: 'var(--text-muted)' }}>Da:</span>{' '}
                <strong>{selectedEmail.fromName ? `${selectedEmail.fromName} <${selectedEmail.fromAddress}>` : selectedEmail.fromAddress}</strong>
              </div>
              <div>
                <span style={{ color: 'var(--text-muted)' }}>A:</span>{' '}
                <strong>{selectedEmail.toAddresses}</strong>
              </div>
              <div>
                <span style={{ color: 'var(--text-muted)' }}>Data Invio:</span>{' '}
                <span>{new Date(selectedEmail.dateSent).toLocaleString()}</span>
              </div>
              <div>
                <span style={{ color: 'var(--text-muted)' }}>Casella:</span>{' '}
                <span>{selectedEmail.mailbox?.name || selectedEmail.mailbox?.emailAddress}</span>
              </div>
              <div>
                <span style={{ color: 'var(--text-muted)' }}>Cartella:</span>{' '}
                <strong style={{ color: selectedEmail.folder === 'SENT' ? '#16a34a' : 'var(--accent)' }}>
                  {selectedEmail.folder === 'SENT' ? 'Posta Inviata' : 'Posta in Arrivo'}
                </strong>
              </div>
            </div>

            {/* Barra Ripristino con Download EML */}
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                padding: '10px 14px',
                borderRadius: 'var(--radius-sm)',
                background: 'rgba(37, 99, 235, 0.08)',
                border: '1px solid rgba(37, 99, 235, 0.2)',
                gap: 12,
                flexWrap: 'wrap'
              }}
            >
              <div style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
                <strong>Formato Standard di Ripristino:</strong> Scarica il file grezzo <strong>.EML</strong> per riaprirlo o trascinarlo (drag & drop) direttamente nella tua casella di Outlook, Thunderbird o Gmail.
              </div>
              <button
                type="button"
                onClick={() => handleDownloadEml(selectedEmail.id, selectedEmail.subject)}
                className="btn btn-primary btn-sm"
                style={{ display: 'flex', alignItems: 'center', gap: 6, flexShrink: 0 }}
              >
                <Download size={14} />
                <span>Scarica File .EML</span>
              </button>
            </div>

            {/* Sezione Allegati */}
            {selectedEmail.attachments && selectedEmail.attachments.length > 0 && (
              <div>
                <div style={{ fontSize: '0.85rem', fontWeight: 600, color: 'var(--text-secondary)', marginBottom: 8 }}>
                  Allegati ({selectedEmail.attachments.length})
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
                  {selectedEmail.attachments.map((att: any) => (
                    <div
                      key={att.id}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '8px 12px',
                        borderRadius: 'var(--radius-sm)',
                        border: '1px solid var(--border-color)',
                        backgroundColor: 'var(--bg-card)'
                      }}
                    >
                      <Paperclip size={16} color="var(--info)" />
                      <div style={{ fontSize: '0.8rem' }}>
                        <div style={{ fontWeight: 600 }}>{att.filename}</div>
                        <div style={{ color: 'var(--text-muted)' }}>{(att.sizeBytes / 1024).toFixed(1)} KB</div>
                      </div>
                      <button
                        onClick={() => handleDownloadAttachment(att.id, att.filename)}
                        className="btn btn-secondary btn-sm"
                        title="Scarica allegato"
                        style={{ marginLeft: 8 }}
                      >
                        <Download size={14} />
                      </button>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Visualizzatore Corpo HTML in Iframe Sandboxed */}
            <div>
              <div style={{ fontSize: '0.85rem', fontWeight: 600, color: 'var(--text-secondary)', marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
                <ShieldAlert size={16} color="var(--success)" />
                <span>Corpo Email (Isolato in Iframe Sandboxed anti-XSS)</span>
              </div>
              <SanitizedHtmlViewer
                htmlContent={selectedEmail.bodyHtmlSanitized || selectedEmail.bodyText}
                theme={theme}
                minHeight={450}
              />
            </div>
          </div>
        ) : null}
      </Modal>

      {/* Modale Esportazione Massiva ZIP / MBOX */}
      <ExportEmailsModal
        isOpen={exportModalOpen}
        onClose={() => setExportModalOpen(false)}
        mailboxes={mailboxes}
        defaultMailboxId={selectedMailbox || 'all'}
      />
    </div>
  );
};
