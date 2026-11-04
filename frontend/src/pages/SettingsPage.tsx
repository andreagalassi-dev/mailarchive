/**
 * src/client/src/pages/SettingsPage.tsx
 * Gestione delle configurazioni applicative e test di notifica (solo ADMIN).
 */
import React, { useEffect, useState, useCallback } from 'react';
import { Settings, Plus, Edit, Trash2, BellRing, Save } from 'lucide-react';
import { api } from '../services/api';
import { useToast } from '../components/Toast';
import { DataTable, Column } from '../components/DataTable';
import { Modal } from '../components/Modal';

export const SettingsPage: React.FC = () => {
  const { showToast } = useToast();
  const [settings, setSettings] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [categoryFilter, setCategoryFilter] = useState('');
  const [testingAlert, setTestingAlert] = useState(false);

  // Modale Edit/Create
  const [modalOpen, setModalOpen] = useState(false);
  const [editingKey, setEditingKey] = useState<string | null>(null);
  const [formKey, setFormKey] = useState('');
  const [formCategory, setFormCategory] = useState('GENERAL');
  const [formDescription, setFormDescription] = useState('');
  const [formValue, setFormValue] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const loadSettings = useCallback(async () => {
    setLoading(true);
    try {
      const url = categoryFilter ? `/api/v1/settings?category=${categoryFilter}` : '/api/v1/settings';
      const res = await api.get(url);
      setSettings(res.data || []);
    } catch (err: any) {
      showToast(err.message || 'Errore nel caricamento delle impostazioni', 'error');
    } finally {
      setLoading(false);
    }
  }, [categoryFilter, showToast]);

  useEffect(() => {
    loadSettings();
  }, [loadSettings]);

  const handleOpenCreate = () => {
    setEditingKey(null);
    setFormKey('');
    setFormCategory('GENERAL');
    setFormDescription('');
    setFormValue('');
    setModalOpen(true);
  };

  const handleOpenEdit = (s: any) => {
    setEditingKey(s.key);
    setFormKey(s.key);
    setFormCategory(s.category);
    setFormDescription(s.description || '');
    setFormValue(typeof s.value === 'object' ? JSON.stringify(s.value, null, 2) : String(s.value));
    setModalOpen(true);
  };

  const handleSaveSetting = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      let parsedValue: any = formValue;
      try {
        parsedValue = JSON.parse(formValue);
      } catch {
        // Se non è JSON valido, trattalo come stringa o numero
        if (!isNaN(Number(formValue)) && formValue.trim() !== '') {
          parsedValue = Number(formValue);
        } else if (formValue.toLowerCase() === 'true') {
          parsedValue = true;
        } else if (formValue.toLowerCase() === 'false') {
          parsedValue = false;
        }
      }

      await api.put(`/api/v1/settings/${formKey}`, {
        value: parsedValue,
        description: formDescription,
        category: formCategory
      });

      showToast(`Impostazione ${formKey} salvata con successo`, 'success');
      setModalOpen(false);
      loadSettings();
    } catch (err: any) {
      showToast(err.message || 'Errore durante il salvataggio dell’impostazione', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDeleteSetting = async (key: string) => {
    if (!window.confirm(`Sei sicuro di voler eliminare l'impostazione "${key}"?`)) return;
    try {
      await api.delete(`/api/v1/settings/${key}`);
      showToast(`Impostazione ${key} eliminata`, 'info');
      loadSettings();
    } catch (err: any) {
      showToast(err.message || 'Errore durante l’eliminazione', 'error');
    }
  };

  const handleTestAlert = async () => {
    setTestingAlert(true);
    try {
      await api.post('/api/v1/alerts/test');
      showToast('Notifica di allarme test inviata ai canali configurati (SMTP/Webhook)', 'success');
    } catch (err: any) {
      showToast(err.message || 'Errore durante l’invio dell’allarme di test', 'error');
    } finally {
      setTestingAlert(false);
    }
  };

  const columns: Column<any>[] = [
    {
      key: 'key',
      label: 'Chiave Impostazione',
      render: (s) => (
        <div>
          <span style={{ fontFamily: 'monospace', fontWeight: 600 }}>{s.key}</span>
          {s.description && (
            <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>{s.description}</div>
          )}
        </div>
      ),
      width: 280
    },
    {
      key: 'category',
      label: 'Categoria',
      render: (s) => <span className="badge badge-secondary">{s.category}</span>,
      width: 130
    },
    {
      key: 'value',
      label: 'Valore Configurato',
      render: (s) => (
        <pre
          style={{
            margin: 0,
            fontFamily: 'monospace',
            fontSize: '0.8rem',
            backgroundColor: 'var(--bg-app)',
            padding: '4px 8px',
            borderRadius: 4,
            maxHeight: 60,
            overflowY: 'auto',
            whiteSpace: 'pre-wrap'
          }}
        >
          {typeof s.value === 'object' ? JSON.stringify(s.value) : String(s.value)}
        </pre>
      )
    },
    {
      key: 'updatedAt',
      label: 'Ultima Modifica',
      render: (s) => (
        <span style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>
          {new Date(s.updatedAt).toLocaleString()}
        </span>
      ),
      width: 170
    },
    {
      key: 'actions',
      label: 'Azioni',
      render: (s) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <button
            onClick={() => handleOpenEdit(s)}
            className="btn btn-secondary btn-sm"
            title="Modifica impostazione"
          >
            <Edit size={14} />
          </button>
          <button
            onClick={() => handleDeleteSetting(s.key)}
            className="btn btn-secondary btn-sm"
            title="Elimina impostazione"
          >
            <Trash2 size={14} color="var(--danger)" />
          </button>
        </div>
      ),
      width: 90
    }
  ];

  return (
    <div className="content-body animate-fade-in" style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2 style={{ fontSize: '1.25rem', fontWeight: 600, color: 'var(--text-primary)' }}>Impostazioni di Sistema</h2>
          <p style={{ fontSize: '0.825rem', color: 'var(--text-secondary)' }}>
            Parametri applicativi, canali di alerting e conservazione dei backup
          </p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <button
            onClick={handleTestAlert}
            disabled={testingAlert}
            className="btn btn-secondary"
            title="Invia una notifica di prova via email e webhook"
          >
            <BellRing size={16} />
            <span>{testingAlert ? 'Invio in corso...' : 'Test Notifica Alert'}</span>
          </button>
          <button onClick={handleOpenCreate} className="btn btn-primary">
            <Plus size={16} />
            <span>Nuova Impostazione</span>
          </button>
        </div>
      </div>

      {/* Filtro per Categoria */}
      <div className="card" style={{ padding: 14 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <label className="form-label" style={{ margin: 0 }}>Filtra per Categoria:</label>
          <select
            className="form-control"
            style={{ width: 220 }}
            value={categoryFilter}
            onChange={(e) => setCategoryFilter(e.target.value)}
          >
            <option value="">Tutte le categorie</option>
            <option value="BACKUP">BACKUP</option>
            <option value="SECURITY">SECURITY</option>
            <option value="NOTIFICATIONS">NOTIFICATIONS</option>
            <option value="STORAGE">STORAGE</option>
            <option value="GENERAL">GENERAL</option>
          </select>
        </div>
      </div>

      <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
        <DataTable columns={columns} data={settings} loading={loading} emptyMessage="Nessuna impostazione trovata" />
      </div>

      {/* Modal Creazione / Modifica Impostazione */}
      <Modal
        isOpen={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editingKey ? `Modifica Impostazione: ${editingKey}` : 'Nuova Impostazione di Sistema'}
      >
        <form onSubmit={handleSaveSetting}>
          <div className="form-group">
            <label className="form-label">Chiave Univoca</label>
            <input
              type="text"
              required
              disabled={Boolean(editingKey)}
              className="form-control"
              placeholder="es. NOTIFICATION_EMAIL"
              value={formKey}
              onChange={(e) => setFormKey(e.target.value)}
            />
          </div>

          <div className="form-group">
            <label className="form-label">Categoria</label>
            <select
              className="form-control"
              value={formCategory}
              onChange={(e) => setFormCategory(e.target.value)}
            >
              <option value="BACKUP">BACKUP</option>
              <option value="SECURITY">SECURITY</option>
              <option value="NOTIFICATIONS">NOTIFICATIONS</option>
              <option value="STORAGE">STORAGE</option>
              <option value="GENERAL">GENERAL</option>
            </select>
          </div>

          <div className="form-group">
            <label className="form-label">Descrizione Funzionale</label>
            <input
              type="text"
              className="form-control"
              placeholder="Descrivi brevemente lo scopo dell'impostazione"
              value={formDescription}
              onChange={(e) => setFormDescription(e.target.value)}
            />
          </div>

          <div className="form-group">
            <label className="form-label">Valore (Stringa, Numero, Booleano o JSON)</label>
            <textarea
              rows={4}
              required
              className="form-control"
              placeholder="es. 365 oppure true oppure { &quot;maxRetries&quot;: 3 }"
              value={formValue}
              onChange={(e) => setFormValue(e.target.value)}
            />
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 24 }}>
            <button type="button" onClick={() => setModalOpen(false)} className="btn btn-secondary">
              Annulla
            </button>
            <button type="submit" disabled={submitting} className="btn btn-primary">
              <Save size={16} />
              <span>{submitting ? 'Salvataggio...' : 'Salva Impostazione'}</span>
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
};
