import { render, useState, useEffect } from '@wordpress/element';
import { Card, CardBody, Button, SelectControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import SignOutModal from './components/Modals/SignOutModal';
import './styles/settings.css';

interface NovaPopup {
    id: string;
    title: string;
    isPublished: boolean;
}

interface PopupsResponse {
    enabled: boolean;
    popups: NovaPopup[];
    selectedId: string | null;
}

function SettingsView() {
    const [isOpen, setOpen] = useState(false);
    const [popupsEnabled, setPopupsEnabled] = useState(false);
    const [popups, setPopups] = useState<NovaPopup[]>([]);
    const [selectedPopup, setSelectedPopup] = useState('');

    const openModal = () => setOpen(true);
    const closeModal = () => setOpen(false);

    // Load the org's pop-ups (Nova only). Degrade silently to hide the feature when unavailable.
    useEffect(() => {
        const loadPopups = async () => {
            try {
                const response = (await apiFetch({ path: '/leadpages/v1/nova/popups' })) as PopupsResponse;
                const published = (response.popups ?? []).filter((popup) => popup.isPublished);
                if (response.enabled && published.length > 0) {
                    setPopupsEnabled(true);
                    setPopups(published);
                    setSelectedPopup(response.selectedId ?? '');
                }
            } catch (e) {
                // Pop-ups are unavailable for this account; keep the feature hidden.
            }
        };
        loadPopups();
    }, []);

    const handlePopupChange = async (value: string) => {
        setSelectedPopup(value);
        try {
            await apiFetch({
                path: '/leadpages/v1/nova/popups',
                method: 'PUT',
                data: { popupId: value === '' ? null : value },
            });
        } catch (e) {
            // Keep the selection in the UI; the save can be retried.
        }
    };

    const popupOptions = [
        { label: 'None', value: '' },
        ...popups.map((popup) => ({ label: popup.title, value: popup.id })),
    ];

    return (
        <div className="root">
            <h1 className="heading">Leadpages</h1>
            <h4>SETTINGS</h4>
            <Card className="settings-card">
                <CardBody size="small">
                    <h3 className="account-header">Leadpages Account</h3>
                    <Button variant="secondary" onClick={openModal}>
                        Sign Out
                    </Button>
                    {isOpen && <SignOutModal onClose={closeModal} />}
                </CardBody>
            </Card>
            {popupsEnabled && (
                <Card className="settings-card">
                    <CardBody size="small">
                        <h3 className="account-header">Pop-ups</h3>
                        <SelectControl
                            label="Show a pop-up across your site"
                            value={selectedPopup}
                            options={popupOptions}
                            onChange={handlePopupChange}
                        />
                    </CardBody>
                </Card>
            )}
        </div>
    );
}

export default SettingsView;

window.addEventListener('load', () => render(<SettingsView />, document.getElementById('settings-page-root')));
