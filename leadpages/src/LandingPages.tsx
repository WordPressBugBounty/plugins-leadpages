import { useEffect, useState, render } from '@wordpress/element';
import { Button, TabPanel } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import { LandingPageTable, actionItemLabel } from './components/LandingPages';
import { PublishModal, UnpublishModal } from './components/Modals';
import NoticeManager from './components/NoticeManager';
import { getErrorMessage } from './utils/api';
import { OrderBy, Direction } from './types/table';
import { LandingPageResponse, MetaData, QueryState, LandingPage, WPResponseError } from './types/api';
import { ModalState } from './types/modal';
import refreshIcon from '../public/refresh-icon.svg';
import './styles/landing_pages.css';

// Page Definitions (For Clarity)
// - "published": a page published in Leadpages
// - "unpublished": a page in draft in Leadpages

// Tab names
const PUBLISHED_TAB = 'Published';
const ADD_PAGE_TAB = 'Add Page';

// Columns needed for each table
const UNPUBLISHED_COLUMNS: Array<keyof LandingPage> = ['name', 'visitors', 'conversion_rate', 'last_published'];
const PUBLISHED_COLUMNS: Array<keyof LandingPage> = [
    'name',
    'wp_slug',
    'visitors',
    'conversion_rate',
    'last_published',
];

// Actions needed for each table
const UNPUBLISHED_ACTIONS = [
    actionItemLabel.EDIT_IN_LEADPAGES,
    actionItemLabel.PUBLISH_TO_WORDPRESS,
    actionItemLabel.VIEW_LEADPAGES_URL,
];
const PUBLISHED_ACTIONS = [
    actionItemLabel.EDIT_PAGE,
    actionItemLabel.EDIT_IN_LEADPAGES,
    actionItemLabel.UNPUBLISH,
    actionItemLabel.VIEW_WORDPRESS_URL,
];

export const QUERY_INITIAL_STATE: QueryState = {
    orderBy: 'date',
    direction: 'desc',
    status: 'published',
    page: 1,
    perPage: 20,
    search: '',
};

function LeadpagesPage() {
    const [loadingData, setLoadingData] = useState(true);
    const [loadingSync, setLoadingSync] = useState(false);
    const [pages, setPages] = useState<Array<LandingPage>>([]);
    const [metaData, setMetaData] = useState<MetaData>({ total: 0, page: 1, perPage: 20 });
    const [tabContent, setTabContent] = useState<JSX.Element | null>(null);
    const [activeTab, setActiveTab] = useState<string>(PUBLISHED_TAB);
    const [query, setQuery] = useState<QueryState>(QUERY_INITIAL_STATE);
    const [modal, setModal] = useState<ModalState>({ show: false, variant: null, page: null });
    const [notice, setNotice] = useState<{ show: boolean; messages: string[] }>({ show: false, messages: [] });
    const [searchQuery, setSearchQuery] = useState('');
    const [searchMode, setSearchMode] = useState<'search' | 'clear'>('search');

    const handleFetchError = (e: WPResponseError) => {
        setNotice((prevNotice) => ({
            show: true,
            messages: [...prevNotice.messages, getErrorMessage(e)],
        }));
    };

    const fetchData = async () => {
        setLoadingData(true);
        try {
            const response = (await apiFetch({
                path: addQueryArgs('/leadpages/v1/pages', query),
                method: 'GET',
            })) as LandingPageResponse;

            const data = response.data;
            const meta = response.meta;
            setPages(data);
            setMetaData(meta);
        } catch (e) {
            handleFetchError(e as WPResponseError);
        } finally {
            setLoadingData(false);
        }
    };

    const syncData = async () => {
        setLoadingSync(true);
        try {
            await apiFetch({
                path: `/leadpages/v1/pages`,
                method: 'POST',
            });
        } catch (e) {
            handleFetchError(e as WPResponseError);
        } finally {
            setLoadingSync(false);
        }
    };

    const handleRefresh = async () => {
        await syncData();
        await fetchData();
    };

    const handleAction = (action: string, page: LandingPage) => {
        switch (action) {
            case actionItemLabel.EDIT_PAGE:
                setModal({ show: true, variant: 'edit', page });
                break;
            case actionItemLabel.UNPUBLISH:
                setModal({ show: true, variant: 'unpublish', page });
                break;
            case actionItemLabel.PUBLISH_TO_WORDPRESS:
                setModal({ show: true, variant: 'publish', page });
                break;
            default:
                break;
        }
    };

    const handleDismissNotice = () => {
        setNotice({ show: false, messages: [] });
    };

    const handlePagination = (page: number, perPage: number) => {
        setQuery({ ...query, page, perPage });
    };

    const handleSortChange = (orderBy: OrderBy, direction: Direction) => {
        setQuery({ ...query, orderBy, direction });
    };

    const handleModalClose = () => {
        setModal({ show: false, variant: null, page: null });
    };

    const handleSearchInput = (e: React.ChangeEvent<HTMLInputElement>) => {
        const search = e.target.value;
        setSearchMode('search');
        setSearchQuery(search);

        // Enables a user to clear their search query by clearing the search input (not just clicking the clear button)
        if (search === '' && query.search !== '') {
            setQuery({ ...query, search: '', page: 1 });
        }
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();

        // Enabling the search button to be used to clear the current search input OR to initiate a new search.
        // To initiate a new search, the search input must not be empty. The search query will already be cleared
        // in the table if the search input is cleared, so we can ignore that here.
        if (searchMode === 'search' && searchQuery !== '') {
            setSearchMode('clear');
            setQuery({ ...query, search: searchQuery, page: 1 });
        } else if (searchMode === 'clear') {
            setSearchMode('search');
            setSearchQuery('');
            setQuery({ ...query, search: '', page: 1 });
        }
    };

    const handleSearchKeyPress = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter') {
            // Prevent form submission if we're in "clear" mode to avoid clearing the input
            // on Enter. It is not intuitive that the Enter key would clear the input in this scenario
            if (searchMode === 'clear') {
                e.preventDefault();
            }
            // In 'search' mode, form submission is handled normally through the submit handler
        }
    };

    const handleTabChange = (tabName: string) => {
        // the WP TabPanel component is calling this function twice only on the publish tab
        // guard against this by checking that the tab actually is being changed
        if (tabName === activeTab) return;
        setActiveTab(tabName);
        if (tabName === PUBLISHED_TAB) {
            setQuery({ ...query, status: 'published', page: 1 });
        } else if (tabName === ADD_PAGE_TAB) {
            setQuery({ ...query, status: 'unpublished', page: 1 });
        }
    };

    useEffect(() => {
        fetchData();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    useEffect(() => {
        syncData();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /**
     * Only render new table content when new data is retrieved from the API. This creates a
     * transition similar to the useDeferredValue hook from React 18, where we continue to show
     * "stale" data in the UI until the new view is ready.
     */
    useEffect(() => {
        if (!loadingData) {
            const content = renderTabContent();
            setTabContent(content);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loadingData]);

    const renderTabContent = () => {
        try {
            let zeroStateMessage = 'No pages available.';
            const props = {
                pages,
                onSortChange: handleSortChange,
                onAction: handleAction,
                pagination: metaData,
                onPagination: handlePagination,
                sortData: {
                    orderBy: query.orderBy === 'date' ? 'last_published' : 'name',
                    direction: query.direction,
                } as { orderBy: OrderBy; direction: Direction },
                columns: [] as Array<keyof LandingPage>,
                actions: [] as string[],
            };

            if (activeTab === ADD_PAGE_TAB) {
                zeroStateMessage =
                    'There are no pages available. Use your Leadpages account to publish pages before adding them to WordPress.';
                props.columns = UNPUBLISHED_COLUMNS;
                props.actions = UNPUBLISHED_ACTIONS;
            } else if (activeTab === PUBLISHED_TAB) {
                zeroStateMessage = 'There are no pages published to WordPress. Use the Add Page tab to publish pages.';
                props.columns = PUBLISHED_COLUMNS;
                props.actions = PUBLISHED_ACTIONS;
            }

            if (query.search !== '') {
                zeroStateMessage =
                    "Sorry, no results found. Try adjusting your search or filters to find what you're looking for.";
            }

            return (
                <>
                    <LandingPageTable {...props} />
                    {pages.length === 0 && !loadingData && (
                        <NoticeManager messages={[zeroStateMessage]} isDismissible={false} />
                    )}
                </>
            );
        } catch (e) {
            // eslint-disable-next-line no-console
            console.error('Error rendering tab content:', e);
        }

        return null;
    };

    /**
     * Fits the call signature that the TabPanel component expects as its children.
     */
    const getTabContent = () => tabContent;

    return (
        <div className="root">
            {notice.show && (
                <NoticeManager
                    messages={notice.messages}
                    status="error"
                    isDismissible={true}
                    onDismiss={handleDismissNotice}
                />
            )}

            <h1 className="heading">Leadpages</h1>
            <h4>{activeTab === ADD_PAGE_TAB ? 'UNPUBLISHED PAGES' : 'PUBLISHED PAGES'}</h4>
            <form className="search-container">
                <input
                    className="search-input"
                    id="search"
                    name="search-query" // Needed in order for the field not to auto-complete
                    value={searchQuery}
                    onChange={handleSearchInput}
                    onKeyDown={handleSearchKeyPress}
                    autoComplete="off"
                />
                <Button className="search-button" variant="secondary" type="submit" onClick={handleSearch}>
                    {searchMode === 'search' ? 'Search' : 'Clear'}
                </Button>
                <Button
                    className="refresh-button"
                    variant="secondary"
                    type="button"
                    onClick={handleRefresh}
                    disabled={loadingSync}
                >
                    <img src={refreshIcon} alt="Refresh icon" />
                    Refresh
                </Button>
            </form>
            <div
                style={{
                    opacity: loadingData || loadingSync ? 0.8 : 1,
                }}
            >
                <TabPanel
                    activeClass="is-active"
                    onSelect={handleTabChange}
                    initialTabName={PUBLISHED_TAB}
                    tabs={[
                        {
                            name: PUBLISHED_TAB,
                            title: PUBLISHED_TAB,
                        },
                        {
                            name: ADD_PAGE_TAB,
                            title: ADD_PAGE_TAB,
                        },
                    ]}
                >
                    {getTabContent}
                </TabPanel>
            </div>
            {modal.show && modal.page && (
                <>
                    {(modal.variant === 'publish' || modal.variant === 'edit') && (
                        <PublishModal
                            variant={modal.variant}
                            onClose={handleModalClose}
                            page={modal.page}
                            onPublish={fetchData}
                        />
                    )}
                    {modal.variant === 'unpublish' && (
                        <UnpublishModal onClose={handleModalClose} page={modal.page} onConfirm={fetchData} />
                    )}
                </>
            )}
        </div>
    );
}

window.addEventListener('load', () => render(<LeadpagesPage />, document.getElementById('leadpages-page-root')));
