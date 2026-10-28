import 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    /** Plain text for the page title and the heading; never user data. */
    title?: string
    /**
     * Who may open the route. `private` (the default when absent) needs a session; `guest` is for visitors only (a
     * signed-in user is sent to /expenses); `public` is open to everyone.
     */
    access?: 'private' | 'guest' | 'public'
  }
}
