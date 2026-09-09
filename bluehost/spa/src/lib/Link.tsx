// Shim for Next's <Link href=...> using react-router's <Link to=...>.
import { Link as RRLink } from "react-router-dom";

export default function Link({ href, children, ...rest }: any) {
  const to = href ?? "#";
  return (
    <RRLink to={to} {...rest}>
      {children}
    </RRLink>
  );
}
