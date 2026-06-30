// Figma: Dashboard — each conversation is a full case record
await figma.loadFontAsync({ family: 'Inter', style: 'Regular' });
await figma.loadFontAsync({ family: 'Inter', style: 'Medium' });
await figma.loadFontAsync({ family: 'Inter', style: 'Semi Bold' });
await figma.loadFontAsync({ family: 'Inter', style: 'Bold' });

const dashboardPage = figma.root.children.find((p) => p.name === 'Dashboard');
await figma.setCurrentPageAsync(dashboardPage);

const main = await figma.getNodeByIdAsync('148:48');
if (!main || main.type !== 'FRAME') return { error: 'Main not found' };

// Remove prior conversation section + global documents
for (const id of ['151:2', '151:84']) {
  const node = await figma.getNodeByIdAsync(id);
  if (node) node.remove();
}

const vars = await figma.variables.getLocalVariablesAsync();
const v = {};
for (const item of vars) v[item.name] = item;

function bindFill(node, varName) {
  const variable = v[varName];
  if (!variable || !node.fills || node.fills === figma.mixed || !node.fills.length) return;
  const paint = figma.variables.setBoundVariableForPaint({ ...node.fills[0], type: 'SOLID' }, 'color', variable);
  node.fills = [paint];
}

function bindStroke(node, varName) {
  const variable = v[varName];
  if (!variable) return;
  const stroke = figma.variables.setBoundVariableForPaint({ type: 'SOLID', color: { r: 0.9, g: 0.91, b: 0.92 } }, 'color', variable);
  node.strokes = [stroke];
}

function makeText(chars, size, weight) {
  const t = figma.createText();
  t.fontName = { family: 'Inter', style: weight };
  t.characters = chars;
  t.fontSize = size;
  t.textAutoResize = 'WIDTH_AND_HEIGHT';
  return t;
}

function makeWrappedText(chars, size, weight, width) {
  const t = makeText(chars, size, weight);
  t.textAutoResize = 'HEIGHT';
  t.resize(width, t.height);
  return t;
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

function makePill(label, state) {
  const pill = figma.createAutoLayout('HORIZONTAL', {
    name: 'Pill',
    paddingLeft: 12,
    paddingRight: 12,
    paddingTop: 4,
    paddingBottom: 4,
    cornerRadius: 999,
    strokeWeight: 1,
  });
  pill.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
  pill.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
  if (state === 'completed') {
    pill.fills = [{ type: 'SOLID', color: { r: 0.925, g: 0.992, b: 0.961 } }];
    pill.strokes = [{ type: 'SOLID', color: { r: 0.655, g: 0.953, b: 0.816 } }];
  } else if (state === 'current') {
    pill.fills = [{ type: 'SOLID', color: { r: 0.933, g: 0.945, b: 1 } }];
    pill.strokes = [{ type: 'SOLID', color: { r: 0.635, g: 0.608, b: 0.98 } }];
  }
  const t = makeText(label, 12, 'Medium');
  bindFill(t, state === 'current' ? 'text/brand' : 'text/secondary');
  pill.appendChild(t);
  return pill;
}

function makeBtn(label, primary) {
  const btn = figma.createAutoLayout('HORIZONTAL', {
    name: label,
    paddingLeft: primary ? 16 : 12,
    paddingRight: primary ? 16 : 12,
    paddingTop: primary ? 10 : 8,
    paddingBottom: primary ? 10 : 8,
    cornerRadius: 8,
    strokeWeight: primary ? 0 : 1,
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

function sectionBlock(titleText, contentWidth) {
  const block = figma.createAutoLayout('VERTICAL', {
    name: titleText,
    paddingLeft: 20,
    paddingRight: 20,
    paddingTop: 20,
    paddingBottom: 20,
    itemSpacing: 12,
    cornerRadius: 10,
    strokeWeight: 1,
  });
  block.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
  block.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
  bindFill(block, 'bg/surface');
  bindStroke(block, 'border/subtle');
  const h = makeText(titleText, 16, 'Semi Bold');
  bindFill(h, 'text/primary');
  block.appendChild(h);
  return block;
}

function makeDocumentsBlock(documents, innerW) {
  const block = sectionBlock('Generated Documents', innerW + 40);

  if (!documents || !documents.length) {
    const empty = makeWrappedText('No documents generated yet for this case.', 13, 'Regular', innerW);
    bindFill(empty, 'text/muted');
    block.appendChild(empty);
    return block;
  }

  documents.forEach((doc) => {
    const row = figma.createAutoLayout('HORIZONTAL', {
      name: 'Document row',
      paddingLeft: 12,
      paddingRight: 12,
      paddingTop: 10,
      paddingBottom: 10,
      itemSpacing: 12,
      cornerRadius: 8,
      strokeWeight: 1,
      primaryAxisAlignItems: 'CENTER',
    });
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
  });

  return block;
}

function makeConversationRecord(data) {
  const contentW = 920;
  const record = figma.createAutoLayout('VERTICAL', {
    name: 'Conversation record',
    paddingLeft: 0,
    paddingRight: 0,
    paddingTop: 0,
    paddingBottom: 0,
    itemSpacing: 0,
    cornerRadius: 12,
    strokeWeight: 1,
  });
  record.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
  record.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
  bindFill(record, 'bg/surface');
  bindStroke(record, 'border/default');

  const header = figma.createAutoLayout('VERTICAL', {
    name: 'Record header',
    paddingLeft: 16,
    paddingRight: 16,
    paddingTop: 16,
    paddingBottom: 16,
    itemSpacing: 0,
  });
  header.fills = [{ type: 'SOLID', color: { r: 0.973, g: 0.98, b: 0.988 } }];
  bindFill(header, 'bg/muted');

  const headerRow = figma.createAutoLayout('HORIZONTAL', {
    name: 'Header row',
    itemSpacing: 20,
    paddingLeft: 16,
    paddingRight: 16,
    paddingTop: 16,
    paddingBottom: 16,
    cornerRadius: 8,
    strokeWeight: 1,
    strokes: [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }],
    counterAxisAlignItems: 'MIN',
  });
  headerRow.fills = [{ type: 'SOLID', color: { r: 1, g: 1, b: 1 } }];
  bindFill(headerRow, 'bg/surface');
  bindStroke(headerRow, 'border/default');
  const mainCol = figma.createAutoLayout('VERTICAL', { name: 'Summary', itemSpacing: 6 });
  const convTitle = makeText(data.title, 18, 'Semi Bold');
  bindFill(convTitle, 'text/brand');
  const preview = makeWrappedText(data.preview, 14, 'Regular', contentW - 180);
  bindFill(preview, 'text/secondary');
  const meta = makeText(data.meta, 12, 'Regular');
  bindFill(meta, 'text/muted');
  mainCol.appendChild(convTitle);
  mainCol.appendChild(preview);
  mainCol.appendChild(meta);

  const actions = figma.createAutoLayout('VERTICAL', { name: 'Actions', itemSpacing: 10 });
  const resumeBtn = makeBtn('Resume', false);
  resumeBtn.strokes = [{ type: 'SOLID', color: { r: 0.796, g: 0.835, b: 0.996 } }];
  bindFill(resumeBtn.children[0], 'text/brand');
  actions.appendChild(resumeBtn);
  actions.appendChild(makeBtn('Remove', false));

  headerRow.appendChild(mainCol);
  headerRow.appendChild(actions);
  header.appendChild(headerRow);
  record.appendChild(header);
  mainCol.layoutSizingHorizontal = 'FILL';
  headerRow.layoutSizingHorizontal = 'FILL';
  header.layoutSizingHorizontal = 'FILL';

  const body = figma.createAutoLayout('VERTICAL', {
    name: 'Record body',
    paddingLeft: 20,
    paddingRight: 20,
    paddingTop: 20,
    paddingBottom: 20,
    itemSpacing: 16,
  });
  record.appendChild(body);
  body.layoutSizingHorizontal = 'FILL';

  const progress = sectionBlock('Case Progress', contentW);
  progress.appendChild(makeText(data.stage, 13, 'Regular'));
  progress.appendChild(makeProgressBar(data.pct, contentW - 40));
  if (data.confidence) {
    progress.appendChild(makeWrappedText('Confidence: ' + data.confidence, 13, 'Regular', contentW - 40));
  }
  if (data.followUp) {
    progress.appendChild(makeWrappedText('Suggested follow-up: ' + data.followUp, 13, 'Regular', contentW - 40));
  }
  if (data.nextStep) {
    progress.appendChild(makeWrappedText('Next likely step: ' + data.nextStep, 13, 'Regular', contentW - 40));
  }
  progress.appendChild(makeText('For your reference only - not a mandatory checklist.', 12, 'Regular'));
  progress.appendChild(makeBtn('Continue Case', true));
  body.appendChild(progress);
  progress.layoutSizingHorizontal = 'FILL';

  const midRow = figma.createAutoLayout('HORIZONTAL', { name: 'Lifecycle and courts', itemSpacing: 16, layoutWrap: 'WRAP' });
  body.appendChild(midRow);
  midRow.layoutSizingHorizontal = 'FILL';

  const halfW = Math.floor((contentW - 16) / 2);
  const life = sectionBlock('Case Lifecycle', halfW);
  const pillsRow = figma.createAutoLayout('HORIZONTAL', { name: 'Milestones', itemSpacing: 8, layoutWrap: 'WRAP' });
  data.milestones.forEach((m) => pillsRow.appendChild(makePill(m.label, m.state)));
  life.appendChild(pillsRow);
  pillsRow.layoutSizingHorizontal = 'FILL';
  const update = makeText('Update milestones', 13, 'Medium');
  bindFill(update, 'text/brand');
  life.appendChild(update);
  life.resize(halfW, life.height);

  const courts = sectionBlock('Courts Involved', halfW);
  data.courts.forEach((court) => {
    const courtItem = figma.createAutoLayout('VERTICAL', {
      name: 'Court',
      paddingLeft: 12,
      paddingRight: 12,
      paddingTop: 8,
      paddingBottom: 8,
      itemSpacing: 4,
      cornerRadius: 8,
      strokeWeight: 1,
    });
    courtItem.fills = [{ type: 'SOLID', color: { r: 0.973, g: 0.98, b: 0.988 } }];
    courtItem.strokes = [{ type: 'SOLID', color: { r: 0.886, g: 0.91, b: 0.941 } }];
    courtItem.appendChild(makeText(court.label, 13, 'Semi Bold'));
    if (court.note) {
      courtItem.appendChild(makeWrappedText(court.note, 12, 'Regular', halfW - 64));
    }
    courts.appendChild(courtItem);
    courtItem.layoutSizingHorizontal = 'FILL';
  });
  courts.resize(halfW, courts.height);

  midRow.appendChild(life);
  midRow.appendChild(courts);

  const docs = makeDocumentsBlock(data.documents, contentW - 40);
  body.appendChild(docs);
  docs.layoutSizingHorizontal = 'FILL';

  return record;
}

const created = [];

const listIntro = figma.createAutoLayout('VERTICAL', { name: 'Cases intro', itemSpacing: 4 });
const listTitle = makeText('Your cases', 18, 'Semi Bold');
bindFill(listTitle, 'text/primary');
const listDesc = makeWrappedText(
  'Each conversation is a self-contained case record with progress, lifecycle, courts, and documents.',
  14,
  'Regular',
  920
);
bindFill(listDesc, 'text/secondary');
listIntro.appendChild(listTitle);
listIntro.appendChild(listDesc);
main.appendChild(listIntro);
listIntro.layoutSizingHorizontal = 'FILL';
created.push(listIntro.id);

const record1 = makeConversationRecord({
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
    { title: 'Summons with Notice (UD-1)', download: true, status: 'Download' },
    { title: 'Affidavit of Service', download: false, status: 'Pending' },
  ],
});

const record2 = makeConversationRecord({
  title: 'Child support modification in Brooklyn',
  preview: 'I need to modify my existing child support order. Income changed last year.',
  meta: 'June 28, 2026, 2:15 pm · 6 messages · Child support modification in Family Court',
  stage: 'Gathering Information · 22% complete',
  pct: 22,
  confidence: 'Low - More details needed to confirm workflow',
  followUp: 'When did your income change?',
  nextStep: 'Financial disclosure - Upload recent pay stubs',
  milestones: [
    { label: 'Eligibility', state: 'completed' },
    { label: 'Intake', state: 'completed' },
    { label: 'Forms', state: 'current' },
    { label: 'Filed', state: 'default' },
    { label: 'Served', state: 'default' },
  ],
  courts: [{ label: 'Family Court - Child Support', note: 'Kings County Family Court' }],
  documents: [],
});

main.appendChild(record1);
main.appendChild(record2);
record1.layoutSizingHorizontal = 'FILL';
record2.layoutSizingHorizontal = 'FILL';
created.push(record1.id, record2.id);

const rootFrame = await figma.getNodeByIdAsync('148:25');
if (rootFrame && 'height' in rootFrame) {
  rootFrame.name = 'Dashboard / Desktop — Conversation Records';
  rootFrame.resize(1440, Math.max(1600, main.y + main.height + 80));
}

const note = await figma.getNodeByIdAsync('152:46');
if (note && 'children' in note && note.children.length > 1) {
  const body = note.children[1];
  if (body.type === 'TEXT') {
    await figma.loadFontAsync(body.fontName);
    body.characters =
      'Each conversation is a full case record: progress, lifecycle, courts, and generated documents.\\nOnly Subscription remains at account level.\\nNo global case widgets.';
  }
}

await main.screenshot({ scale: 0.4 });

return {
  createdNodeIds: created,
  mutatedNodeIds: [main.id, rootFrame ? rootFrame.id : null, note ? note.id : null],
  removedNodeIds: ['151:2', '151:84'],
};
