import { useEffect, useState } from 'react';

/**
 * Returns `value`, but only after it's stopped changing for `delayMs`.
 * Used to keep a search box's keystrokes from firing a network request
 * on every character — the caller renders the input against the live
 * (uncontrolled-delay) value and feeds this debounced one to the query.
 */
export function useDebouncedValue<T>(value: T, delayMs = 350): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delayMs);
        return () => clearTimeout(timer);
    }, [value, delayMs]);

    return debounced;
}
