const element = (type, style, children, extra = {}) => ({type, props: {style, children, ...extra}})

const icon = (color, size) =>
  element(
    'svg',
    {width: size, height: size},
    [
      element('path', {}, undefined, {
        d: 'M14 8h27a5 5 0 0 1 5 5v43a5 5 0 0 1-5 5H14a5 5 0 0 1-5-5V18z',
        fill: 'none',
        stroke: color,
        strokeWidth: 4,
        strokeLinejoin: 'round',
      }),
      ...[24, 34, 44].map(y =>
        element('path', {}, undefined, {
          d: `M18 ${y}h19`,
          stroke: color,
          strokeWidth: 4,
          strokeLinecap: 'round',
        }),
      ),
    ],
    {viewBox: '0 0 56 68'},
  )

export function template(payload) {
  const hasImage = payload.image !== null
  const titleSize = payload.title.length > 70 ? 42 : payload.title.length > 40 ? 48 : 56
  return element(
    'div',
    {
      width: 1200,
      height: 630,
      padding: 0,
      display: 'flex',
      background: '#ffffff',
      fontFamily: 'Inter',
    },
    element(
      'div',
      {
        width: '100%',
        height: '100%',
        padding: 40,
        background: '#ffffff',
        display: 'flex',
        position: 'relative',
        overflow: 'hidden',
      },
      [
        element(
          'div',
          {
            display: 'flex',
            flexDirection: 'column',
            width: hasImage ? 600 : 1050,
            paddingRight: hasImage ? 30 : 0,
          },
          [
            element(
              'div',
              {
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'center',
                flexGrow: 1,
                overflow: 'hidden',
              },
              [
                element(
                  'div',
                  {
                    fontSize: titleSize,
                    fontWeight: 700,
                    lineHeight: 1.2,
                    color: '#090909',
                    lineClamp: 3,
                    overflow: 'hidden',
                    wordBreak: 'break-word',
                  },
                  payload.title,
                ),
                element(
                  'div',
                  {
                    fontSize: 25,
                    lineHeight: 1.42,
                    marginTop: 22,
                    color: '#626975',
                    lineClamp: 5,
                    overflow: 'hidden',
                    wordBreak: 'break-word',
                  },
                  payload.description,
                ),
                element('div', {display: 'flex', alignItems: 'center', gap: 10, marginTop: 28}, [
                  icon('#9297a0', 20),
                  element('div', {fontSize: 16, color: '#9297a0'}, payload.branding),
                ]),
              ],
            ),
          ],
        ),
        ...(hasImage
          ? [
              element('img', {width: 520, height: 550, objectFit: 'cover'}, undefined, {
                src: payload.image,
                width: 520,
                height: 550,
              }),
            ]
          : []),
      ],
    ),
  )
}
