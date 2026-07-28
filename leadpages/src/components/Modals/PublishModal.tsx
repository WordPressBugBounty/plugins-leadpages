import { useState } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import NoticeManager from '../NoticeManager';
import { type LandingPage } from '../PageTable';
import { HOME_URL } from '../../utils/config';
import { WPResponseError } from '../../types/api';
import { getErrorMessage } from '../../utils/api';
import { Variants } from '../../types/modal';
import './modal.css';

export interface Props {
    variant: Variants;
    page: LandingPage;
    onClose: () => void;
    onPublish: () => void;
}

const PublishModal: React.FC<Props> = ({ variant = 'publish', page, onClose, onPublish }: Props) => {
    const [slug, setSlug] = useState(page.wp_slug ?? page.lp_slug ?? '');
    const [loading, setLoading] = useState(false);
    const [cacheClearSuccess, setCacheClearSuccess] = useState(false);
    const [notice, setNotice] = useState<{ show: boolean; messages: string[] }>({ show: false, messages: [] });
    const [replaceConflict, setReplaceConflict] = useState<string | null>(null);

    const handleInput = (e: React.ChangeEvent<HTMLInputElement>) => {
        setSlug(e.target.value);
        // The conflict is specific to a slug, so a new slug clears any pending replace prompt.
        setReplaceConflict(null);
    };

    const handleDismissNotice = () => {
        setNotice({ show: false, messages: [] });
    };

    const handleFetchError = (e: WPResponseError) => {
        setNotice((prevNotice) => ({
            show: true,
            messages: [...prevNotice.messages, getErrorMessage(e)],
        }));
    };

    const handlePublish = async (replace = false) => {
        if (!page) return;
        setLoading(true);

        try {
            await apiFetch({
                path: `/leadpages/v1/pages/${page.id}`,
                method: 'PUT',
                data: {
                    slug,
                    published: true, // this only manages published pages
                    pageType: null, // we don't support specific page types yet
                    replace, // replace a page from either platform already at this slug
                },
            });
            onPublish();
            onClose();
        } catch (e) {
            const error = e as WPResponseError;
            // A landing page (possibly on the other platform) already holds this slug. Offer to
            // hand the slug off rather than failing, preserving the same URL.
            if (error.code === 'slug_taken_by_page') {
                setReplaceConflict(error.message);
            } else {
                handleFetchError(error);
            }
        } finally {
            setLoading(false);
        }
    };

    const clearCache = async () => {
        try {
            await apiFetch({
                path: `/leadpages/v1/pages/${page.id}/cache`,
                method: 'DELETE',
            });
            // show success message and clear it after 2 seconds
            setCacheClearSuccess(true);
            setTimeout(() => setCacheClearSuccess(false), 2000);
        } catch (e) {
            handleFetchError(e as WPResponseError);
        }
    };

    const buttonText = variant === 'publish' ? 'Publish' : 'Save';

    // Setting width to 512px explicitly as the Modal "size" prop is relatively new
    // Setting max-height to 50vh just so that the modal isn't huge on mobile and all content is visible when there is an error
    return (
        <Modal
            className="lp-modal"
            title="Page Settings"
            onRequestClose={onClose}
            style={{ width: '512px', maxHeight: '75vh' }}
        >
            <p className="modal-page-info modal-grey">{page.name}</p>
            <hr className="modal-section-break" />
            {notice.show && (
                <NoticeManager
                    messages={notice.messages}
                    onDismiss={handleDismissNotice}
                    isDismissible
                    status="error"
                />
            )}
            <div className="modal-section">
                <div className="modal-section-header">
                    <h4>Slug</h4>
                    <p className="modal-grey">This is the url to view your landing page on your site</p>
                </div>
                <div className="modal-slug">
                    {/* @ts-ignore - WP magic injects this variable in Assets.php */}
                    <p className="modal-grey">{HOME_URL + '/'}</p>
                    <input onChange={handleInput} value={slug} />
                </div>
            </div>
            {variant === 'edit' && (
                <>
                    <hr className="modal-section-break" />
                    <div className="modal-section">
                        <div className="modal-section-header">
                            <h4>Page Cache</h4>
                            <p className="modal-grey">Unavailable for split tests</p>
                        </div>
                        <Button
                            className="modal-button"
                            variant="secondary"
                            onClick={clearCache}
                            disabled={page.kind === 'LeadpageSplitTestV2'}
                        >
                            {cacheClearSuccess ? 'Success' : 'Clear Cache'}
                        </Button>
                    </div>
                </>
            )}
            {replaceConflict && (
                <div className="modal-section modal-replace-conflict">
                    <p className="modal-grey">{replaceConflict}</p>
                    <Button variant="secondary" disabled={loading} onClick={() => handlePublish(true)}>
                        {loading ? 'Loading...' : `Replace the page at /${slug}`}
                    </Button>
                </div>
            )}
            {/* Add a little extra padding to the publish modal only. We can remove this when we add page types. */}
            <div className={variant === 'publish' ? 'modal-actions modal-action-padding' : 'modal-actions'}>
                <Button variant="tertiary" onClick={onClose}>
                    Cancel
                </Button>
                <Button variant="primary" disabled={!slug || loading} onClick={() => handlePublish()}>
                    {loading ? 'Loading...' : buttonText}
                </Button>
            </div>
        </Modal>
    );
};

export default PublishModal;
