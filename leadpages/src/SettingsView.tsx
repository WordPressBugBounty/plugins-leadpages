import { render, useState, useEffect, useMemo } from '@wordpress/element';
import { Card, CardBody, Button, SelectControl, RadioControl, FormTokenField } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import SignOutModal from './components/Modals/SignOutModal';
import type { LandingPageResponse } from './types/api';
import './styles/settings.css';

interface NovaPopup {
    id: string;
    title: string;
    isPublished: boolean;
}

type ScopeMode = 'wordpress' | 'wordpress_and_leadpages' | 'specific';

interface PopupScope {
    mode: ScopeMode;
    slugs: string[];
}

interface PopupsResponse {
    enabled: boolean;
    popups: NovaPopup[];
    selectedId: string | null;
    scope: PopupScope;
}

interface WpRestItem {
    slug: string;
    title: { rendered: string };
}

interface SitePage {
    slug: string;
    label: string;
    source: 'wordpress' | 'leadpages';
}

const mergeSitePagesBySlug = (pages: SitePage[]): SitePage[] => {
    const bySlug = new Map<string, SitePage>();
    pages.forEach((page) => {
        if (page.slug && !bySlug.has(page.slug)) {
            bySlug.set(page.slug, page);
        }
    });
    return Array.from(bySlug.values()).sort((first, second) => first.label.localeCompare(second.label));
};

const fetchOrEmpty = async <ResultType,>(path: string, fallback: ResultType): Promise<ResultType> => {
    try {
        return (await apiFetch({ path })) as ResultType;
    } catch (err) {
        // A missing or failing source is skipped so the remaining pages still populate the selector.
        return fallback;
    }
};

const toScopeMode = (value: string): ScopeMode =>
    value === 'wordpress_and_leadpages' || value === 'specific' ? value : 'wordpress';

function SettingsView() {
    const [isOpen, setOpen] = useState(false);
    const [popupsEnabled, setPopupsEnabled] = useState(false);
    const [popups, setPopups] = useState<NovaPopup[]>([]);
    const [selectedPopup, setSelectedPopup] = useState('');
    const [scopeMode, setScopeMode] = useState<ScopeMode>('wordpress');
    const [selectedSlugs, setSelectedSlugs] = useState<string[]>([]);
    const [sitePages, setSitePages] = useState<SitePage[]>([]);

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
                    if (response.scope) {
                        setScopeMode(response.scope.mode);
                        setSelectedSlugs(response.scope.slugs ?? []);
                    }
                }
            } catch (err) {
                // Pop-ups are unavailable for this account; keep the feature hidden.
            }
        };
        loadPopups();
    }, []);

    // Load the site's pages/posts and connected Leadpages pages for the "specific pages" selector.
    // Best-effort: any source that fails is skipped so the remaining pages still populate the list.
    useEffect(() => {
        if (!popupsEnabled) {
            return;
        }
        const loadSitePages = async () => {
            const [wpPageItems, wpPostItems, leadpagesResponse] = await Promise.all([
                fetchOrEmpty<WpRestItem[]>('/wp/v2/pages?per_page=100&status=publish&_fields=slug,title', []),
                fetchOrEmpty<WpRestItem[]>('/wp/v2/posts?per_page=100&status=publish&_fields=slug,title', []),
                fetchOrEmpty<Pick<LandingPageResponse, 'data'>>('/leadpages/v1/pages?status=published&perPage=100', {
                    data: [],
                }),
            ]);

            const wpPages: SitePage[] = [...wpPageItems, ...wpPostItems]
                .filter((item) => item.slug)
                .map((item) => ({ slug: item.slug, label: item.title?.rendered || item.slug, source: 'wordpress' as const }));

            const leadpages: SitePage[] = (leadpagesResponse.data ?? [])
                .filter((page) => page.wp_slug)
                .map((page) => ({ slug: page.wp_slug, label: page.name || page.wp_slug, source: 'leadpages' as const }));

            setSitePages(mergeSitePagesBySlug([...wpPages, ...leadpages]));
        };
        loadSitePages();
    }, [popupsEnabled]);

    // The scope stores slugs, but the field shows page titles. Map both ways, disambiguating any
    // duplicate titles with their slug so each display value resolves to exactly one page.
    const { slugToDisplay, displayToSlug } = useMemo(() => {
        // Leadpages pages are marked so they are distinguishable from WordPress pages with the same
        // title; any remaining duplicate display labels are disambiguated with the slug.
        const baseLabel = (page: SitePage) =>
            'leadpages' === page.source ? `${page.label} (Leadpages)` : page.label;
        const labelCounts = new Map<string, number>();
        sitePages.forEach((page) => {
            const base = baseLabel(page);
            labelCounts.set(base, (labelCounts.get(base) ?? 0) + 1);
        });
        const slugToDisplayMap = new Map<string, string>();
        const displayToSlugMap = new Map<string, string>();
        sitePages.forEach((page) => {
            const base = baseLabel(page);
            const display = (labelCounts.get(base) ?? 0) > 1 ? `${base} (/${page.slug})` : base;
            slugToDisplayMap.set(page.slug, display);
            displayToSlugMap.set(display, page.slug);
        });
        return { slugToDisplay: slugToDisplayMap, displayToSlug: displayToSlugMap };
    }, [sitePages]);

    const openModal = () => setOpen(true);
    const closeModal = () => setOpen(false);

    const saveScope = async (mode: ScopeMode, slugs: string[]) => {
        try {
            await apiFetch({
                path: '/leadpages/v1/nova/popups',
                method: 'PUT',
                data: { scopeMode: mode, slugs },
            });
        } catch (err) {
            // Keep the selection in the UI; the save can be retried.
        }
    };

    const handlePopupChange = async (value: string) => {
        setSelectedPopup(value);
        try {
            await apiFetch({
                path: '/leadpages/v1/nova/popups',
                method: 'PUT',
                data: { popupId: value === '' ? null : value },
            });
        } catch (err) {
            // Keep the selection in the UI; the save can be retried.
        }
    };

    const handleScopeModeChange = (mode: string) => {
        const nextMode = toScopeMode(mode);
        setScopeMode(nextMode);
        saveScope(nextMode, selectedSlugs);
    };

    const handleTokensChange = (tokens: Array<string | { value: string }>) => {
        const nextSlugs = tokens
            .map((token) => (typeof token === 'string' ? token : token.value))
            .map((display) => displayToSlug.get(display) ?? display)
            .filter((slug, index, all) => all.indexOf(slug) === index);
        setSelectedSlugs(nextSlugs);
        saveScope('specific', nextSlugs);
    };

    const popupOptions = [
        { label: 'None', value: '' },
        ...popups.map((popup) => ({ label: popup.title, value: popup.id })),
    ];

    const pageSuggestions = sitePages.map((page) => slugToDisplay.get(page.slug) ?? page.slug);
    const selectedPageTokens = selectedSlugs.map((slug) => slugToDisplay.get(slug) ?? slug);

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
                            label="Choose a pop-up"
                            value={selectedPopup}
                            options={popupOptions}
                            onChange={handlePopupChange}
                        />
                        <RadioControl
                            label="Where the pop-up appears"
                            selected={scopeMode}
                            options={[
                                { label: 'All WordPress pages', value: 'wordpress' },
                                { label: 'WordPress + Leadpages pages', value: 'wordpress_and_leadpages' },
                                { label: 'Specific pages', value: 'specific' },
                            ]}
                            onChange={handleScopeModeChange}
                        />
                        {'specific' === scopeMode && (
                            <div className="popup-scope-pages">
                                <FormTokenField
                                    label="Pages"
                                    value={selectedPageTokens}
                                    suggestions={pageSuggestions}
                                    onChange={handleTokensChange}
                                    __experimentalExpandOnFocus
                                    __experimentalValidateInput={(input: string) => displayToSlug.has(input)}
                                    placeholder={
                                        sitePages.length === 0 ? 'No published pages found' : 'Search pages to add'
                                    }
                                />
                            </div>
                        )}
                    </CardBody>
                </Card>
            )}
        </div>
    );
}

export default SettingsView;

window.addEventListener('load', () => {
    const container = document.getElementById('settings-page-root');
    if (container) {
        render(<SettingsView />, container);
    }
});
