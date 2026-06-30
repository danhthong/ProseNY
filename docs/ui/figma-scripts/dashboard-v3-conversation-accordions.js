// Figma: Dashboard — conversation accordions (first expanded by default)
// Run on Dashboard page. Fixes: auto-layout main order, HUG buttons/pills, horizontal actions.
await figma.loadFontAsync({ family: 'Inter', style: 'Regular' });
await figma.loadFontAsync({ family: 'Inter', style: 'Medium' });
await figma.loadFontAsync({ family: 'Inter', style: 'Semi Bold' });

const dashboardPage = figma.root.children.find((p) => p.name === 'Dashboard');
await figma.setCurrentPageAsync(dashboardPage);

const main = await figma.getNodeByIdAsync('148:48');
if (!main || main.type !== 'FRAME') return { error: 'Main not found' };

// Remove prior accordion list if re-running.
for (const child of [...main.children]) {
	if (child.name === 'Conversation accordions') {
		child.remove();
	}
}

main.layoutMode = 'VERTICAL';
main.primaryAxisAlignItems = 'MIN';
main.counterAxisAlignItems = 'MIN';
main.itemSpacing = 24;
main.paddingLeft = 32;
main.paddingRight = 32;
main.paddingTop = 32;
main.paddingBottom = 32;

const subscription = await figma.getNodeByIdAsync('149:6');
const pageHeader = await figma.getNodeByIdAsync('149:3');
const casesIntro = await figma.getNodeByIdAsync('156:46');

const vars = await figma.variables.getLocalVariablesAsync();
const v = {};
for (const item of vars) v[item.name] = item;

function bindFill(node, varName) {
	const variable = v[varName];
	if (!variable || !node.fills || node.fills === figma.mixed || !node.fills.length) return;
	node.fills = [figma.variables.setBoundVariableForPaint({ ...node.fills[0], type: 'SOLID' }, 'color', variable)];
}

function bindStroke(node, varName) {
	const variable = v[varName];
	if (!variable) return;
	node.strokes = [figma.variables.setBoundVariableForPaint({ type: 'SOLID', color: { r: 0.9, g: 0.91, b: 0.92 } }, 'color', variable)];
}

function makeText(chars, size, weight) {
	const t = figma.createText();
	t.fontName = { family: 'Inter', style: weight };
	t.characters = chars;
	t.fontSize = size;
	t.textAutoResize = 'WIDTH_AND_HEIGHT';
	return t;
}

function makeWrapped(chars, size, weight, width) {
	const t = makeText(chars, size, weight);
	t.textAutoResize = 'HEIGHT';
	t.resize(width, t.height);
	return t;
}

function alFrame(name, dir, opts) {
	const f = figma.createFrame();
	f.name = name;
	f.layoutMode = dir === 'H' ? 'HORIZONTAL' : 'VERTICAL';
	f.primaryAxisSizingMode = 'AUTO';
	f.counterAxisSizingMode = 'AUTO';
	if (opts) {
		if (opts.gap != null) f.itemSpacing = opts.gap;
		if (opts.pl != null) f.paddingLeft = opts.pl;
		if (opts.pr != null) f.paddingRight = opts.pr;
		if (opts.pt != null) f.paddingTop = opts.pt;
		if (opts.pb != null) f.paddingBottom = opts.pb;
		if (opts.radius != null) f.cornerRadius = opts.radius;
		if (opts.stroke != null) f.strokeWeight = opts.stroke;
		if (opts.align) f.counterAxisAlignItems = opts.align;
		if (opts.wrap) f.layoutWrap = 'WRAP';
	}
	return f;
}

function makeBtn(label, primary) {
	const btn = alFrame(label, 'H', {
		pl: primary ? 16 : 12,
		pr: primary ? 16 : 12,
		pt: primary ? 10 : 8,
		pb: primary ? 10 : 8,
		radius: 8,
		stroke: primary ? 0 : 1,
		align: 'CENTER',
	});
	if (primary) {
		btn.fills = [{ type: 'SOLID', color: { r: 0.31, g: 0.275, b: 0.898 } }];
		bindFill(btn, 'brand/solid');
	} else {
		btn.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
		btn.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
	}
	const t = makeText(label, 13, 'Medium');
	bindFill(t, primary ? 'brand/on-solid' : 'text/secondary');
	btn.appendChild(t);
	return btn;
}

function makePill(label, state) {
	const pill = alFrame('Pill', 'H', { pl: 12, pr: 12, pt: 4, pb: 4, radius: 999, stroke: 1, align: 'CENTER' });
	pill.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
	pill.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
	if (state === 'completed') {
		pill.fills = [{ type: 'SOLID', color: { r: 0.925, g: 0.992, b: 0.961 } }];
		pill.strokes = [{ type: 'SOLID', color: { r: 0.655, g: 0.953, b: 0.816 } }];
	} else if (state === 'current') {
		pill.fills = [{ type: 'SOLID', color: { r: 0.933, g: 0.945, b: 1 } }];
		pill.strokes = [{ type: 'SOLID', color: { r: 0.635, g: 0.608, b: 0.98 } }];
	}
	pill.appendChild(makeText(label, 12, 'Medium'));
	bindFill(pill.children[0], state === 'current' ? 'text/brand' : 'text/secondary');
	return pill;
}

function sectionBlock(title) {
	const block = alFrame(title, 'V', { gap: 12, pl: 20, pr: 20, pt: 20, pb: 20, radius: 10, stroke: 1 });
	block.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
	block.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
	bindFill(block, 'bg/surface');
	bindStroke(block, 'border/subtle');
	const h = makeText(title, 16, 'Semi Bold');
	bindFill(h, 'text/primary');
	block.appendChild(h);
	return block;
}

function makeProgressBar(pct, width) {
	const track = figma.createFrame();
	track.name = 'Progress track';
	track.resize(width, 8);
	track.cornerRadius = 999;
	track.fills = [{ type: 'SOLID', color: { r: 0.945, g: 0.961, b: 0.976 } }];
	bindFill(track, 'bg/muted');
	track.layoutMode = 'NONE';
	const fill = figma.createRectangle();
	fill.resize(Math.max(8, Math.round(width * pct / 100)), 8);
	fill.cornerRadius = 999;
	fill.fills = [{ type: 'SOLID', color: { r: 0.31, g: 0.275, b: 0.898 } }];
	bindFill(fill, 'brand/solid');
	track.appendChild(fill);
	return track;
}

function makeDocumentsBlock(documents, innerW) {
	const block = sectionBlock('Generated Documents');
	if (!documents || !documents.length) {
		const empty = makeWrapped('No documents generated yet for this case.', 13, 'Regular', innerW);
		bindFill(empty, 'text/muted');
		block.appendChild(empty);
		return block;
	}
	documents.forEach((doc) => {
		const row = alFrame('Document row', 'H', { pl: 12, pr: 12, pt: 10, pb: 10, gap: 12, radius: 8, stroke: 1, align: 'CENTER' });
		row.fills = [{ type: 'SOLID', color: { r: 0.973, g: 0.98, b: 0.988 } }];
		row.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
		const title = makeText(doc.title, 13, 'Semi Bold');
		bindFill(title, 'text/primary');
		const status = makeText(doc.status || 'Download', 13, 'Medium');
		bindFill(status, doc.download ? 'text/brand' : 'text/muted');
		row.appendChild(title);
		row.appendChild(status);
		block.appendChild(row);
		row.layoutSizingHorizontal = 'FILL';
		title.layoutSizingHorizontal = 'FILL';
		status.layoutSizingHorizontal = 'HUG';
	});
	return block;
}

function makeRecordBody(data, contentW) {
	const body = alFrame('Accordion panel', 'V', { gap: 16, pl: 20, pr: 20, pt: 16, pb: 20 });
	body.fills = [];

	if (data.preview) {
		const preview = makeWrapped(data.preview, 14, 'Regular', contentW);
		bindFill(preview, 'text/secondary');
		preview.name = 'Case preview';
		body.appendChild(preview);
		preview.layoutSizingHorizontal = 'FILL';
	}

	const progress = sectionBlock('Case Progress');
	progress.appendChild(makeText(data.stage, 13, 'Regular'));
	progress.appendChild(makeProgressBar(data.pct, contentW - 40));
	if (data.confidence) progress.appendChild(makeWrapped('Confidence: ' + data.confidence, 13, 'Regular', contentW - 40));
	if (data.followUp) progress.appendChild(makeWrapped('Suggested follow-up: ' + data.followUp, 13, 'Regular', contentW - 40));
	if (data.nextStep) progress.appendChild(makeWrapped('Next likely step: ' + data.nextStep, 13, 'Regular', contentW - 40));
	progress.appendChild(makeText('For your reference only - not a mandatory checklist.', 12, 'Regular'));
	progress.appendChild(makeBtn('Continue Case', true));
	body.appendChild(progress);
	progress.layoutSizingHorizontal = 'FILL';

	const midRow = alFrame('Lifecycle and courts', 'H', { gap: 16, wrap: true });
	body.appendChild(midRow);
	midRow.layoutSizingHorizontal = 'FILL';

	const halfW = Math.floor((contentW - 16) / 2);
	const life = sectionBlock('Case Lifecycle');
	const pillsRow = alFrame('Milestones', 'H', { gap: 8, wrap: true });
	data.milestones.forEach((m) => pillsRow.appendChild(makePill(m.label, m.state)));
	life.appendChild(pillsRow);
	pillsRow.layoutSizingHorizontal = 'FILL';
	const update = makeText('Update milestones', 13, 'Medium');
	bindFill(update, 'text/brand');
	life.appendChild(update);

	const courts = sectionBlock('Courts Involved');
	data.courts.forEach((court) => {
		const courtItem = alFrame('Court', 'V', { gap: 4, pl: 12, pr: 12, pt: 8, pb: 8, radius: 8, stroke: 1 });
		courtItem.fills = [{ type: 'SOLID', color: { r: 0.973, g: 0.98, b: 0.988 } }];
		courtItem.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
		courtItem.appendChild(makeText(court.label, 13, 'Semi Bold'));
		if (court.note) courtItem.appendChild(makeWrapped(court.note, 12, 'Regular', halfW - 64));
		courts.appendChild(courtItem);
		courtItem.layoutSizingHorizontal = 'FILL';
	});

	midRow.appendChild(life);
	midRow.appendChild(courts);
	life.layoutSizingHorizontal = 'FILL';
	courts.layoutSizingHorizontal = 'FILL';

	const docs = makeDocumentsBlock(data.documents, contentW - 40);
	body.appendChild(docs);
	docs.layoutSizingHorizontal = 'FILL';
	return body;
}

function makeAccordionTrigger(data, expanded, contentW) {
	const trigger = alFrame('Accordion trigger', 'H', { gap: 12, pl: 16, pr: 16, pt: 16, pb: 16, align: 'CENTER' });
	trigger.fills = expanded
		? [{ type: 'SOLID', color: { r: 0.973, g: 0.98, b: 0.988 } }]
		: [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
	if (expanded) bindFill(trigger, 'bg/muted');

	const chevron = makeText(expanded ? '−' : '+', 20, 'Semi Bold');
	chevron.name = 'Chevron';
	bindFill(chevron, 'text/brand');
	trigger.appendChild(chevron);
	chevron.layoutSizingHorizontal = 'HUG';
	chevron.layoutSizingVertical = 'HUG';

	const summary = alFrame('Summary', 'V', { gap: 4 });
	summary.fills = [];
	const title = makeText(data.title, 16, 'Semi Bold');
	bindFill(title, 'text/primary');
	summary.appendChild(title);
	const meta = makeText(data.meta, 12, 'Regular');
	bindFill(meta, 'text/muted');
	summary.appendChild(meta);
	trigger.appendChild(summary);
	summary.layoutSizingHorizontal = 'FILL';
	summary.layoutSizingVertical = 'HUG';

	const actions = alFrame('Actions', 'H', { gap: 8, align: 'CENTER' });
	actions.fills = [];
	const resumeBtn = makeBtn('Resume', false);
	resumeBtn.strokes = [{ type: 'SOLID', color: { r: 0.796, g: 0.835, b: 0.996 } }];
	bindFill(resumeBtn.children[0], 'text/brand');
	actions.appendChild(resumeBtn);
	actions.appendChild(makeBtn('Remove', false));
	trigger.appendChild(actions);
	actions.layoutSizingHorizontal = 'HUG';
	actions.layoutSizingVertical = 'HUG';

	return trigger;
}

function makeAccordionItem(data, expanded) {
	const contentW = 920;
	const item = alFrame(expanded ? 'Accordion item (expanded)' : 'Accordion item (collapsed)', 'V', { gap: 0, radius: 12, stroke: 1 });
	item.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
	item.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
	bindFill(item, 'bg/surface');
	bindStroke(item, 'border/default');

	const trigger = makeAccordionTrigger(data, expanded, contentW);
	item.appendChild(trigger);
	trigger.layoutSizingHorizontal = 'FILL';

	if (expanded) {
		const divider = figma.createRectangle();
		divider.name = 'Divider';
		divider.resize(10, 1);
		divider.fills = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
		bindFill(divider, 'border/subtle');
		item.appendChild(divider);
		divider.layoutSizingHorizontal = 'FILL';

		const body = makeRecordBody(data, contentW);
		item.appendChild(body);
		body.layoutSizingHorizontal = 'FILL';
	}

	return item;
}

const accordionList = alFrame('Conversation accordions', 'V', { gap: 12 });
accordionList.fills = [];

const record1 = makeAccordionItem(
	{
		title: 'I need to file for divorce in New York City',
		preview: 'I need to file for divorce in New York City. My spouse and I agree on everything.',
		meta: 'June 30, 2026, 6:50 am · 4 messages · Uncontested matrimonial action in NYC Supreme Court',
		stage: 'Starting the Case · 7% complete',
		pct: 7,
		confidence: 'Moderate - A likely workflow path is identified; some details may still be helpful.',
		followUp: 'In which NYC county are you filing?',
		nextStep: 'Service - Serving court papers in New York so the other party receives notice.',
		milestones: [
			{ label: 'Eligibility', state: 'completed' },
			{ label: 'Intake', state: 'current' },
			{ label: 'Forms', state: 'default' },
			{ label: 'Filed', state: 'default' },
			{ label: 'Served', state: 'default' },
			{ label: 'Answer', state: 'default' },
			{ label: 'Settlement', state: 'default' },
			{ label: 'Judgment', state: 'default' },
			{ label: 'Closed', state: 'default' },
		],
		courts: [
			{ label: 'Supreme Court - Divorce', note: '' },
			{ label: 'Family Court - Custody', note: 'May proceed alongside your divorce case.' },
		],
		documents: [
			{ title: 'Get Documents (UD-1)', download: true, status: 'Download' },
			{ title: 'Get Documents (UD-1A and UD-2)', download: true, status: 'Download' },
		],
	},
	true
);

const record2 = makeAccordionItem(
	{
		title: 'Child support modification in Brooklyn',
		meta: 'June 28, 2026, 2:15 pm · 6 messages · Child support modification in Family Court',
		stage: 'Gathering Information · 22% complete',
		pct: 22,
		milestones: [
			{ label: 'Eligibility', state: 'completed' },
			{ label: 'Intake', state: 'completed' },
			{ label: 'Forms', state: 'current' },
		],
		courts: [{ label: 'Family Court - Child Support', note: 'Kings County Family Court' }],
		documents: [],
	},
	false
);

accordionList.appendChild(record1);
accordionList.appendChild(record2);
record1.layoutSizingHorizontal = 'FILL';
record2.layoutSizingHorizontal = 'FILL';

if (pageHeader) {
	main.appendChild(pageHeader);
	pageHeader.layoutSizingHorizontal = 'FILL';
}
if (casesIntro) {
	casesIntro.layoutMode = 'VERTICAL';
	casesIntro.itemSpacing = 4;
	main.appendChild(casesIntro);
	casesIntro.layoutSizingHorizontal = 'FILL';
}
main.appendChild(accordionList);
accordionList.layoutSizingHorizontal = 'FILL';
if (subscription) {
	main.appendChild(subscription);
	subscription.layoutSizingHorizontal = 'FILL';
}

const rootFrame = await figma.getNodeByIdAsync('148:25');
if (rootFrame && 'height' in rootFrame) {
	rootFrame.name = 'Dashboard / Desktop — Conversation Accordions';
	rootFrame.resize(1440, Math.max(1200, main.height + 200));
}

return {
	createdNodeIds: [accordionList.id, record1.id, record2.id],
	mutatedNodeIds: [main.id, rootFrame ? rootFrame.id : null],
};
