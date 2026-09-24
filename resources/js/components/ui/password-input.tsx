import * as React from "react"
import { Eye, EyeOff } from "lucide-react"
import { cn } from "cn"

import { Input } from "./input"

/**
 * <Input type="password"> plus an eye-icon toggle to reveal the value.
 * Wraps the existing Input primitive (doesn't reimplement it) so it keeps
 * every focus/invalid/disabled style already defined there.
 */
export interface PasswordInputProps
  extends Omit<React.ComponentProps<typeof Input>, "type"> {
  containerClassName?: string
}

const PasswordInput = React.forwardRef<HTMLInputElement, PasswordInputProps>(
  ({ className, containerClassName, ...props }, ref) => {
    const [visible, setVisible] = React.useState(false)

    return (
      <div className={cn("relative", containerClassName)}>
        <Input
          ref={ref}
          type={visible ? "text" : "password"}
          className={cn("pr-9", className)}
          {...props}
        />
        <button
          type="button"
          // Keep this out of tab order between the field and the next
          // input — it's a convenience toggle, not a form control users
          // need to reach by keyboard navigation.
          tabIndex={-1}
          onClick={() => setVisible((current) => !current)}
          className="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-muted-foreground transition-colors hover:text-foreground"
          aria-label={visible ? "Hide password" : "Show password"}
          aria-pressed={visible}
        >
          {visible ? (
            <EyeOff className="size-4" />
          ) : (
            <Eye className="size-4" />
          )}
        </button>
      </div>
    )
  }
)
PasswordInput.displayName = "PasswordInput"

export { PasswordInput }
