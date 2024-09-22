import { useState } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import { LandingPage, WPResponseError } from '../../types/api';
import NoticeManager from '../NoticeManager';
import { getErrorMessage } from '../../utils/api';
import './modal.css';

export interface Props {
    page: LandingPage;
    onClose: () => void;
    onConfirm: () => void;
}

const UnpublishModal: React.FC<Props> = ({ onClose, onConfirm, page }: Props) => {
    const [loading, setLoading] = useState(false);
    const [notice, setNotice] = useState<{ show: boolean; messages: string[] }>({ show: false, messages: [] });

    const handleDismissNotice = () => {
        setNotice({ show: false, messages: [] });
    };

    const handleFetchError = (e: WPResponseError) => {
        setNotice((prevNotice) => ({
            show: true,
            messages: [...prevNotice.messages, getErrorMessage(e)],
        }));
    };

    const handleUnpublish = async () => {
        if (!page) return;
        setLoading(true);

        try {
            await apiFetch({
                path: `/leadpages/v1/pages/${page.id}`,
                method: 'PUT',
                data: {
                    slug: null,
                    published: false,
                    pageType: null,
                },
            });
            onConfirm();
            onClose();
        } catch (e) {
            handleFetchError(e as WPResponseError);
        } finally {
            setLoading(false);
        }
    };

    // Setting width to 384px explicitly as the Modal "size" prop is relatively new
    // Setting max-height to 35vh just so that the modal isn't huge on mobile and all content is visible when there is an error
    return (
        <Modal
            className="lp-modal"
            title="Are You Sure?"
            onRequestClose={onClose}
            style={{ width: '384px', maxHeight: '35vh' }}
        >
            {notice.show && (
                <NoticeManager
                    status="error"
                    messages={notice.messages}
                    onDismiss={handleDismissNotice}
                    isDismissible
                />
            )}
            <p>This page will be unpublished in WordPress. It will remain published in Leadpages.</p>
            <div className="modal-actions">
                <Button variant="tertiary" onClick={onClose}>
                    Cancel
                </Button>
                <Button variant="primary" onClick={handleUnpublish}>
                    {loading ? 'Loading..' : 'Unpublish'}
                </Button>
            </div>
        </Modal>
    );
};

export default UnpublishModal;
