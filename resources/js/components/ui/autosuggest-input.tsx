import * as React from "react"
import { cn } from "cn"

import { Input } from "@/components/ui/input"

export interface AutosuggestInputProps
  extends Omit<React.ComponentProps<typeof Input>, "value" | "onChange"> {
  value: string
  onChange: (value: string) => void
  /** Full candidate list — filtered client-side against the current value. */
  suggestions: string[]
  /** Caps how many matches are shown at once. */
  maxSuggestions?: number
}

/**
 * A plain-text input that autosuggests from a fixed list as the user
 * types — for fields like Major/Year level/Section that are free text
 * (no enum backs them) but usually repeat values already on file.
 * Deliberately built on the existing Input primitive rather than a new
 * dependency: matching is a simple case-insensitive substring filter,
 * with arrow-key/Enter/Escape support and click-outside-to-close.
 */
function AutosuggestInput({
  value,
  onChange,
  suggestions,
  maxSuggestions = 8,
  className,
  onFocus,
  onKeyDown,
  ...props
}: AutosuggestInputProps) {
  const [isOpen, setIsOpen] = React.useState(false)
  const [highlightedIndex, setHighlightedIndex] = React.useState(-1)
  const containerRef = React.useRef<HTMLDivElement>(null)

  const filtered = React.useMemo(() => {
    const query = value.trim().toLowerCase()
    const matches = query
      ? suggestions.filter((s) => s.toLowerCase().includes(query) && s.toLowerCase() !== query)
      : suggestions
    return matches.slice(0, maxSuggestions)
  }, [value, suggestions, maxSuggestions])

  React.useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }
    document.addEventListener("mousedown", handleClickOutside)
    return () => document.removeEventListener("mousedown", handleClickOutside)
  }, [])

  function selectSuggestion(suggestion: string) {
    onChange(suggestion)
    setIsOpen(false)
    setHighlightedIndex(-1)
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    onKeyDown?.(event)
    if (!isOpen || filtered.length === 0) return

    if (event.key === "ArrowDown") {
      event.preventDefault()
      setHighlightedIndex((i) => (i + 1) % filtered.length)
    } else if (event.key === "ArrowUp") {
      event.preventDefault()
      setHighlightedIndex((i) => (i <= 0 ? filtered.length - 1 : i - 1))
    } else if (event.key === "Enter" && highlightedIndex >= 0) {
      event.preventDefault()
      selectSuggestion(filtered[highlightedIndex])
    } else if (event.key === "Escape") {
      setIsOpen(false)
    }
  }

  return (
    <div ref={containerRef} className="relative">
      <Input
        {...props}
        value={value}
        autoComplete="off"
        role="combobox"
        aria-expanded={isOpen}
        aria-autocomplete="list"
        className={className}
        onChange={(event) => {
          onChange(event.target.value)
          setIsOpen(true)
          setHighlightedIndex(-1)
        }}
        onFocus={(event) => {
          setIsOpen(true)
          onFocus?.(event)
        }}
        onKeyDown={handleKeyDown}
      />
      {isOpen && filtered.length > 0 && (
        <div className="absolute z-10 mt-1 max-h-48 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md">
          {filtered.map((suggestion, index) => (
            <button
              key={suggestion}
              type="button"
              className={cn(
                "block w-full truncate rounded-sm px-2 py-1.5 text-left text-sm",
                index === highlightedIndex
                  ? "bg-accent text-accent-foreground"
                  : "hover:bg-accent hover:text-accent-foreground",
              )}
              // Fires before the input's blur, so the click actually
              // registers instead of the dropdown closing out from under it.
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => selectSuggestion(suggestion)}
            >
              {suggestion}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

export { AutosuggestInput }
