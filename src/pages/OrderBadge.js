/**
 * Order-list badge, drawn exactly like the PHP one (includes/Order/Labels.php):
 * same dashicon, same colours. Keep both in sync.
 */
const PILL = {
	display: 'inline-flex',
	alignItems: 'center',
	gap: '4px',
	padding: '2px 8px',
	borderRadius: '4px',
	fontWeight: 600,
	lineHeight: '20px',
	whiteSpace: 'nowrap',
};
const ICON = { width: 16, height: 16, fontSize: 16, lineHeight: 1 };

const STYLES = {
	new: { background: '#e7f5e9', color: '#1a6b2c' },
	trusted: { background: '#e7f5e9', color: '#1a6b2c' },
	unknown: { background: '#f0f0f1', color: '#3c434a' },
	risky: { background: '#fcf0e1', color: '#8a4600' },
	blocked: { background: '#fbe7e7', color: '#a1191d' },
	none: { background: '#f0f0f1', color: '#3c434a' },
};

/**
 * @param {Object} props
 * @param {string} props.kind  new|trusted|unknown|risky|blocked|none.
 * @param {string} props.icon  Dashicon name without the prefix.
 * @param {string} props.label Badge text.
 * @param {number} props.score Optional score shown after the label.
 */
export default function OrderBadge( { kind, icon, label, score } ) {
	return (
		<span
			className={ `trustorder-badge trustorder-level-${ kind }` }
			style={ { ...PILL, ...( STYLES[ kind ] || STYLES.none ) } }
		>
			<span
				className={ `dashicons dashicons-${ icon }` }
				style={ ICON }
				aria-hidden="true"
			/>
			{ label }
			{ undefined !== score && (
				<>
					{ ' ' }
					<span className="trustorder-score">{ score }</span>
				</>
			) }
		</span>
	);
}
