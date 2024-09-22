import React from 'react';

interface CaretProps {
    up: boolean;
}

const Caret: React.FC<CaretProps> = ({ up }: CaretProps) => {
    return (
        <svg
            width="16"
            height="16"
            viewBox="0 0 16 16"
            fill="none"
            className={`caret ${up ? 'up' : 'down'}`}
            xmlns="http://www.w3.org/2000/svg"
        >
            <path
                d="M8 11.3333L12.6667 6.66667L11.3333 5.33333L8 8.66667L4.66667 5.33333L3.33333 6.66667L8 11.3333Z"
                fill="#3858e9"
            />
        </svg>
    );
};

export default Caret;
