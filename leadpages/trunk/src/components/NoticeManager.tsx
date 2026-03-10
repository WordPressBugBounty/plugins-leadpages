import React from 'react';
import { Notice } from '@wordpress/components';
import '../styles/wordpress_global.css';

interface NoticeManagerProps {
    messages: string[];
    status?: 'error' | 'success';
    isDismissible: boolean;
    onDismiss?: () => void;
}

const NoticeManager: React.FC<NoticeManagerProps> = ({ messages, status, isDismissible = true, onDismiss }) => {
    return (
        <Notice className="lp-notice" status={status} isDismissible={isDismissible} onDismiss={onDismiss}>
            {messages.map((message, index) => (
                <div key={index}>{message}</div>
            ))}
        </Notice>
    );
};

export default NoticeManager;
