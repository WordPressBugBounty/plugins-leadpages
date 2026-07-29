import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { info } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

import './login.css';
import { getErrorMessage } from '../../utils/api';
import { WPResponseError } from '../../types/api';
import leadpagesLogo from '../../../public/leadpages-logo.png';

const SIGN_UP_URL =
    'https://www.leadpages.com/pricing?utm_campaign=Trial%20to%20Paid&utm_source=wordpress&utm_medium=wordpress&utm_term=wordpress';
const OAUTH_CHANNEL = 'oauth_channel';

type OAuthChannelData = {
    success: boolean;
    error: string;
};

export interface Props {
    onLoginSuccess: () => void;
}

const Login: React.FC<Props> = ({ onLoginSuccess }) => {
    const [error, setError] = useState('');

    // Start an OAuth connect flow against the given authorize endpoint. Both Classic and the new
    // Leadpages (Nova) use the same PKCE popup + BroadcastChannel flow; only the endpoint differs.
    const startConnect = async (authorizePath: string) => {
        try {
            const url = (await apiFetch({
                path: authorizePath,
            })) as string;
            window.open(url, 'oauth2Popup', 'width=800,height=800');
        } catch (err) {
            setError(getErrorMessage(err as WPResponseError));
            return;
        }

        // Await the result of connecting and handle success or error responses
        const oauthChannel = new BroadcastChannel(OAUTH_CHANNEL);
        oauthChannel.onmessage = (event: MessageEvent<OAuthChannelData>) => {
            if (event.data.success === true) {
                onLoginSuccess();
                oauthChannel.close();
            } else {
                setError(event.data.error);
                oauthChannel.close();
            }
        };
    };

    const handleNovaConnectClick = () => startConnect('leadpages/v1/nova/authorize');

    const handleLoginClick = () => startConnect('leadpages/v1/oauth2/authorize');

    return (
        <div className="login-root">
            <div className="login-signin">
                <div className="login-brand">
                    <img className="login-logo" src={leadpagesLogo} alt="Leadpages" />
                    <span className="login-for-wp">for WordPress</span>
                </div>

                <h1 className="login-title">Connect your account to start publishing</h1>
                <p className="login-lede">
                    Link Leadpages to this site, then publish any page to a clean URL on your own domain.
                </p>

                {error && (
                    <div className="lp-alert lp-alert-error alert">
                        <div className="lp-alert-icon lp-error-icon rotate-180">{info}</div>
                        <div className="lp-alert-message lp-message-error">{error}</div>
                    </div>
                )}

                <div className="login-connects">
                    <Button className="marketing-button contained" onClick={handleNovaConnectClick}>
                        Connect the new Leadpages
                    </Button>
                    <Button className="marketing-button outlined" onClick={handleLoginClick}>
                        Log in to Classic Leadpages
                    </Button>
                </div>

                <p className="login-signup">
                    New to Leadpages?{' '}
                    <a href={SIGN_UP_URL} target="_blank" rel="noopener noreferrer">
                        Start free
                    </a>
                </p>

                <div className="login-trust">
                    <span className="login-trust-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                            <path d="M12 3 5 6v6c0 4 3 6.5 7 9 4-2.5 7-5 7-9V6l-7-3Z" />
                        </svg>
                        Secure one-click sign-in
                    </span>
                    <span>No DNS changes</span>
                </div>
            </div>

            <div className="login-hero">
                <div className="login-kicker">The new Leadpages</div>
                <h2 className="login-hero-title">
                    Landing pages your AI builds. <em>Live on your WordPress site.</em>
                </h2>

                <div className="login-compose">
                    <div className="login-prompt">
                        <span className="login-spark">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 2l1.8 4.7L18.5 8l-4.7 1.8L12 14l-1.8-4.2L5.5 8l4.7-1.3L12 2z" />
                            </svg>
                        </span>
                        <span className="login-prompt-txt">
                            A webinar registration page for our Q3 product launch
                            <span className="login-caret" />
                        </span>
                    </div>
                    <div className="login-genrow">
                        <span className="login-pulse" /> Generating copy, layout and images…
                    </div>
                    <div className="login-preview">
                        <span className="pv-h" />
                        <span className="pv-t pv-t-2" />
                        <span className="pv-media" />
                        <span className="pv-form">
                            <span className="pv-field" />
                            <span className="pv-btn" />
                        </span>
                        <span className="pv-t pv-t-3" />
                    </div>
                </div>

                <div className="login-chips">
                    <span className="login-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                            <path d="M4 12h16M14 6l6 6-6 6" />
                        </svg>
                        Publish to any URL, no DNS
                    </span>
                    <span className="login-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                            <path d="M4 4v6h6M20 20v-6h-6M20 9A8 8 0 0 0 6 6M4 15a8 8 0 0 0 14 3" />
                        </svg>
                        Edits sync automatically
                    </span>
                    <span className="login-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                            <path d="M4 19V5M4 19h16M8 15l3-4 3 3 4-6" />
                        </svg>
                        Built-in analytics
                    </span>
                </div>
            </div>
        </div>
    );
};

export default Login;
