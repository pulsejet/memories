// Prettier is disabled for the builders below so requests are laid out like the XML they build.

const NS = {
  d: 'DAV:',
  oc: 'http://owncloud.org/ns',
  nc: 'http://nextcloud.org/ns',
};

type Tag = `${keyof typeof NS}:${string}`;

function el(tag: Tag, ...children: (Element | string)[]) {
  const element = document.createElementNS(NS[tag.split(':')[0] as keyof typeof NS], tag);
  element.append(...children);
  return element;
}

function serialize(root: Element) {
  return new XMLSerializer().serializeToString(root);
}

/** Build a PROPFIND body requesting the given properties. */
// prettier-ignore
export function propfind(props: Tag[]) {
  return serialize(
    el('d:propfind',
      el('d:prop', ...props.map((prop) => el(prop))),
    ),
  );
}

/** Build a PROPPATCH body setting the given properties, skipping undefined values. */
// prettier-ignore
export function propertyUpdate<T extends Tag>(props: Partial<Record<T, string>>) {
  const elements: Element[] = [];
  for (const tag in props) {
    const value = props[tag];
    if (value !== undefined) {
      elements.push(el(tag, value));
    }
  }

  return serialize(
    el('d:propertyupdate',
      el('d:set',
        el('d:prop', ...elements),
      ),
    ),
  );
}

/** Build a SEARCH body finding the given file IDs under a scope. */
// prettier-ignore
export function searchByFileIds(scope: string, fileIds: number[]) {
  const matches = fileIds.map((id) =>
    el('d:eq',
      el('d:prop', el('oc:fileid')),
      el('d:literal', String(id)),
    ),
  );

  return serialize(
    el('d:searchrequest',
      el('d:basicsearch',
        el('d:select',
          el('d:prop', el('oc:fileid')),
        ),
        el('d:from',
          el('d:scope',
            el('d:href', scope),
            el('d:depth', '0'),
          ),
        ),
        el('d:where',
          el('d:or', ...matches),
        ),
      ),
    ),
  );
}
