import React, { useState } from 'react';
import { Modal, Button } from '@wordpress/components';
import leadpagesWordmark from '../../../public/lp-wordmark-251x42.png';
import './sign_out_modal.css';
import apiFetch from '@wordpress/api-fetch';

export interface SignOutConfirmationProps {
    onClose: () => void;
}

const basePath = 'admin.php?page=';
const SLUG_LEADPAGES = 'leadpages';

const SignOut: React.FC<SignOutConfirmationProps> = ({ onClose }) => {
    const [error, setError] = useState<string | null>(null);

    const handleSignOut = async () => {
        try {
            await apiFetch({ path: '/leadpages/v1/oauth2/sign-out', method: 'GET' });
            window.location.replace(basePath + SLUG_LEADPAGES);
        } catch (e) {
            // eslint-disable-next-line no-console
            console.log('Error signing out', e);
            setError('Error signing out. Please try again.');
        }
    };

    return (
        <>
            <Modal onRequestClose={onClose} style={{ width: '750px', maxHeight: '60vh' }}>
                <div className="modal-root">
                    <img src={leadpagesWordmark} alt="Leadpages Wordmark" />
                    <h1 className="sign-out-header">Are you sure?</h1>
                    <p className="sign-out-body">
                        Any landing pages published with the plugin will remain published after you sign out.
                        <br />
                        You may deactivate the plugin to unpublish all landing pages in WordPress.
                    </p>
                    <div>
                        <p>
                            <strong>Do you need support?</strong>
                            <br />
                            Please{' '}
                            <a
                                className="support-link"
                                href="https://support.leadpages.com/hc/en-us/articles/205046170"
                                target="_blank"
                                rel="noreferrer"
                            >
                                {' '}
                                contact our support team
                            </a>{' '}
                            before you go.
                        </p>
                    </div>
                    <div className="cta-buttons">
                        <Button className="product-button text" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button className="product-button contained" onClick={handleSignOut}>
                            Sign Out
                        </Button>
                    </div>
                    {error && <p className="error-message">{error}</p>}
                </div>
            </Modal>
        </>
    );
};

export default SignOut;
