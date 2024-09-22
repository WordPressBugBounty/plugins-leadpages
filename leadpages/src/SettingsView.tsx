import { render, useState } from '@wordpress/element';
import { Card, CardBody, Button } from '@wordpress/components';
import SignOutModal from './components/Modals/SignOutModal';
import './styles/settings.css';

function SettingsView() {
    const [isOpen, setOpen] = useState(false);
    const openModal = () => setOpen(true);
    const closeModal = () => setOpen(false);

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
        </div>
    );
}

export default SettingsView;

window.addEventListener('load', () => render(<SettingsView />, document.getElementById('settings-page-root')));
