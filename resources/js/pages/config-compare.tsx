import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import {
    compare as configCompareCompare,
    index as configCompareIndex,
    transform as configCompareTransform,
} from '@/routes/config-compare';
import configCompareEntries from '@/routes/config-compare/entries';
import configCompareRuns from '@/routes/config-compare/runs';
import { type BreadcrumbItem } from '@/types';

type ReportSource = {
    key: string;
    label: string;
    path: string | null;
    exists: boolean;
    mtime: string | null;
};

type HistoryEntry = {
    id: string;
    entry_id: number;
    type: 'config' | 'sql';
    name: string;
    status: string;
    created_at: string | null;
    payload: Record<string, unknown>;
};

type PageProps = {
    report_sources: Record<string, ReportSource>;
    history: HistoryEntry[];
    notice?: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Config Compare',
        href: configCompareIndex().url,
    },
];

const DEFAULT_LABELS = {
    dev2_to_test: { from: 'DEV2', to: 'TEST' },
    test_to_dev2: { from: 'TEST', to: 'DEV2' },
} as const;

const getDefaultKeys = (sources: ReportSource[]) => {
    if (sources.length === 0) {
        return { left: '', right: '' };
    }
    if (sources.length === 1) {
        return { left: sources[0].key, right: sources[0].key };
    }
    return { left: sources[0].key, right: sources[1].key };
};

const formatDate = (value: string | null) => {
    if (!value) {
        return 'n/a';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }
    return date.toISOString().replace('T', ' ').replace('Z', '');
};

const getStatusBadge = (status: string) => {
    if (status === 'completed' || status === 'saved') {
        return 'secondary';
    }
    if (status === 'error' || status === 'mismatch') {
        return 'destructive';
    }
    return 'outline';
};

const getStatusLabel = (status: string) => {
    if (status === 'completed') {
        return 'ok';
    }
    if (status === 'saved') {
        return 'enregistre';
    }
    if (status === 'mismatch') {
        return 'diff';
    }
    if (status === 'error') {
        return 'erreur';
    }
    return status;
};

const formatIssue = (issue: unknown) => {
    if (!issue) {
        return '';
    }
    if (typeof issue === 'string') {
        return issue;
    }
    if (typeof issue !== 'object') {
        return String(issue);
    }
    const entry = issue as Record<string, unknown>;
    const type = String(entry.type ?? 'issue');
    const alias = entry.alias ? ` alias ${entry.alias}` : '';
    const column = entry.column ? ` colonne ${entry.column}` : '';
    const objects = Array.isArray(entry.objects) ? entry.objects.join(', ') : entry.objects;
    const targets = Array.isArray(entry.targets) ? entry.targets.join(', ') : entry.targets;

    if (type === 'object_table_missing') {
        return `Mapping table manquant${alias} (objets: ${objects ?? 'n/a'}).`;
    }
    if (type === 'object_table_conflict') {
        return `Conflit de mapping table${alias} (objets: ${objects ?? 'n/a'}).`;
    }
    if (type === 'object_column_missing') {
        return `Mapping colonne manquant${alias}${column} (objets: ${objects ?? 'n/a'}).`;
    }
    if (type === 'object_column_conflict') {
        return `Conflit mapping colonne${alias}${column} (cibles: ${targets ?? 'n/a'}).`;
    }
    if (type === 'object_context_missing') {
        return `Objet non detecte${alias}${column}.`;
    }
    if (type === 'object_alias_missing') {
        return `Alias manquant pour objets: ${objects ?? 'n/a'}.`;
    }

    return `${type}${alias}${column}`;
};

type ManualCompareRow = {
    index: number;
    dev2Field: string;
    testField: string;
    match: boolean;
};

type ManualCompareState = {
    dev2Table: string;
    testTable: string;
    rows: ManualCompareRow[];
};

type SqlDiffRow = {
    left?: string;
    right?: string;
    type: 'same' | 'add' | 'remove';
};

type SqlDiffLine = SqlDiffRow & {
    leftNo?: number;
    rightNo?: number;
    key: string;
};

const buildSqlDiffRows = (leftSql: string, rightSql: string): SqlDiffRow[] => {
    // Line-level diff using a simple LCS table.
    const leftLines = leftSql.split(/\r?\n/);
    const rightLines = rightSql.split(/\r?\n/);
    const leftCount = leftLines.length;
    const rightCount = rightLines.length;
    const dp = Array.from({ length: leftCount + 1 }, () =>
        new Array(rightCount + 1).fill(0),
    );

    for (let i = leftCount - 1; i >= 0; i -= 1) {
        for (let j = rightCount - 1; j >= 0; j -= 1) {
            if (leftLines[i] === rightLines[j]) {
                dp[i][j] = dp[i + 1][j + 1] + 1;
            } else {
                dp[i][j] = Math.max(dp[i + 1][j], dp[i][j + 1]);
            }
        }
    }

    const rows: SqlDiffRow[] = [];
    let i = 0;
    let j = 0;

    while (i < leftCount && j < rightCount) {
        if (leftLines[i] === rightLines[j]) {
            rows.push({ type: 'same', left: leftLines[i], right: rightLines[j] });
            i += 1;
            j += 1;
        } else if (dp[i + 1][j] >= dp[i][j + 1]) {
            rows.push({ type: 'remove', left: leftLines[i] });
            i += 1;
        } else {
            rows.push({ type: 'add', right: rightLines[j] });
            j += 1;
        }
    }

    while (i < leftCount) {
        rows.push({ type: 'remove', left: leftLines[i] });
        i += 1;
    }

    while (j < rightCount) {
        rows.push({ type: 'add', right: rightLines[j] });
        j += 1;
    }

    return rows;
};

const parseFields = (value: string): string[] =>
    value
        .split(/[\r\n,]+/)
        .map((entry) => entry.trim())
        .filter(Boolean);

const buildManualRows = (dev2Fields: string[], testFields: string[]): ManualCompareRow[] => {
    const total = Math.max(dev2Fields.length, testFields.length);
    return Array.from({ length: total }, (_, index) => {
        const dev2Field = dev2Fields[index] ?? '';
        const testField = testFields[index] ?? '';
        return {
            index: index + 1,
            dev2Field,
            testField,
            match: dev2Field !== '' && dev2Field === testField,
        };
    });
};
export default function ConfigCompare() {
    const { props } = usePage<PageProps>();
    const reportList = Object.values(props.report_sources ?? {});
    const defaults = getDefaultKeys(reportList);
    const [compareOpen, setCompareOpen] = useState(false);
    const [manualOpen, setManualOpen] = useState(false);
    const [sqlOpen, setSqlOpen] = useState(false);
    const [detailOpen, setDetailOpen] = useState(false);
    const [diffOpen, setDiffOpen] = useState(false);
    const [selectedEntry, setSelectedEntry] = useState<HistoryEntry | null>(null);
    const [filterText, setFilterText] = useState('');
    const [sortKey, setSortKey] = useState<'date' | 'type' | 'name' | 'status'>('date');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
    const [copyMessage, setCopyMessage] = useState<string | null>(props.notice ?? null);
    const [manualCompare, setManualCompare] = useState<ManualCompareState | null>(null);
    const [manualForm, setManualForm] = useState({
        dev2Table: '',
        testTable: '',
        dev2Fields: '',
        testFields: '',
    });

    useEffect(() => {
        if (!props.notice) {
            return;
        }
        setCopyMessage(props.notice);
        const timer = setTimeout(() => setCopyMessage(null), 2000);
        return () => clearTimeout(timer);
    }, [props.notice]);

    const compareForm = useForm({
        left_key: defaults.left,
        right_key: defaults.right,
        left_file: null as File | null,
        right_file: null as File | null,
        row_scan_limit: '',
        sheet_suffix: '',
    });

    const sqlForm = useForm({
        name: '',
        direction: 'dev2_to_test',
        sql: '',
        source_label: DEFAULT_LABELS.dev2_to_test.from,
        target_label: DEFAULT_LABELS.dev2_to_test.to,
    });

    const filteredHistory = useMemo(() => {
        const list = props.history ?? [];
        const needle = filterText.trim().toLowerCase();
        if (needle === '') {
            return list;
        }
        return list.filter((item) => {
            const payloadText = JSON.stringify(item.payload ?? {});
            const haystack = [item.type, item.name, item.status, payloadText].join(' ').toLowerCase();
            return haystack.includes(needle);
        });
    }, [filterText, props.history]);

    const sortedHistory = useMemo(() => {
        const list = [...filteredHistory];
        list.sort((left, right) => {
            let compare = 0;
            if (sortKey === 'date') {
                compare = String(left.created_at).localeCompare(String(right.created_at));
            } else if (sortKey === 'type') {
                compare = left.type.localeCompare(right.type);
            } else if (sortKey === 'status') {
                compare = left.status.localeCompare(right.status);
            } else {
                compare = left.name.localeCompare(right.name);
            }
            return sortDir === 'asc' ? compare : compare * -1;
        });
        return list;
    }, [filteredHistory, sortDir, sortKey]);

    const handleSort = (key: typeof sortKey) => {
        if (key === sortKey) {
            setSortDir((prev) => (prev === 'asc' ? 'desc' : 'asc'));
            return;
        }
        setSortKey(key);
        setSortDir('asc');
    };
    const copyText = async (value: string, label: string) => {
        if (!value) {
            setCopyMessage('Rien a copier.');
            return;
        }
        try {
            if (navigator?.clipboard?.writeText) {
                await navigator.clipboard.writeText(value);
            }
            setCopyMessage(`Copie: ${label}.`);
        } catch {
            setCopyMessage('Echec de copie.');
        } finally {
            setTimeout(() => setCopyMessage(null), 2000);
        }
    };

    const openDetail = (entry: HistoryEntry) => {
        setSelectedEntry(entry);
        setDetailOpen(true);
    };

    const openDiff = (entry: HistoryEntry) => {
        setSelectedEntry(entry);
        setDiffOpen(true);
    };

    const handleReuse = (entry: HistoryEntry) => {
        const payload = entry.payload as Record<string, unknown>;
        const entryName = String(payload.name ?? '');
        const direction = String(payload.direction ?? 'dev2_to_test');
        const sourceLabel = String(payload.source_label ?? DEFAULT_LABELS.dev2_to_test.from);
        const targetLabel = String(payload.target_label ?? DEFAULT_LABELS.dev2_to_test.to);

        sqlForm.setData({
            name: entryName,
            direction,
            sql: String(payload.input_sql ?? ''),
            source_label: sourceLabel,
            target_label: targetLabel,
        });
        setSqlOpen(true);
    };

    const submitManualCompare = (event: FormEvent) => {
        event.preventDefault();
        const dev2Fields = parseFields(manualForm.dev2Fields);
        const testFields = parseFields(manualForm.testFields);
        setManualCompare({
            dev2Table: manualForm.dev2Table.trim(),
            testTable: manualForm.testTable.trim(),
            rows: buildManualRows(dev2Fields, testFields),
        });
        setManualOpen(false);
    };

    const handleDelete = (entry: HistoryEntry) => {
        const message =
            entry.type === 'config'
                ? 'Supprimer cette comparaison ?'
                : 'Supprimer ce SQL ?';
        if (!window.confirm(message)) {
            return;
        }

        const url =
            entry.type === 'config'
                ? configCompareRuns.destroy(entry.entry_id).url
                : configCompareEntries.destroy(entry.entry_id).url;

        router.delete(url, { preserveScroll: true });
    };

    const submitCompare = (event: FormEvent) => {
        event.preventDefault();
        compareForm.post(configCompareCompare().url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setCompareOpen(false),
        });
    };

    const submitTransform = (event: FormEvent) => {
        event.preventDefault();
        sqlForm.post(configCompareTransform().url, {
            preserveScroll: true,
            onSuccess: () => setSqlOpen(false),
        });
    };

    const diffPayload = selectedEntry?.type === 'config' ? selectedEntry.payload : null;
    const diffChanges = Array.isArray(diffPayload?.changes) ? diffPayload?.changes : [];
    const diffLeftOnly = Array.isArray(diffPayload?.only_in_left) ? diffPayload?.only_in_left : [];
    const diffRightOnly = Array.isArray(diffPayload?.only_in_right) ? diffPayload?.only_in_right : [];
    const strictOk = Boolean(diffPayload?.strict_ok);
    const sqlPayload = selectedEntry?.type === 'sql' ? selectedEntry.payload : null;
    const sqlInput = String(sqlPayload?.input_sql ?? '');
    const sqlOutput = String(sqlPayload?.output_sql ?? '');
    const sqlIssues = useMemo(() => {
        if (!sqlPayload || typeof sqlPayload !== 'object') {
            return [];
        }
        const issues = (sqlPayload as Record<string, unknown>).issues;
        return Array.isArray(issues) ? issues : [];
    }, [sqlPayload]);
    const sqlDiffRows = useMemo(() => {
        if (!selectedEntry || selectedEntry.type !== 'sql') {
            return [];
        }
        if (sqlInput === '' && sqlOutput === '') {
            return [];
        }
        return buildSqlDiffRows(sqlInput, sqlOutput);
    }, [selectedEntry, sqlInput, sqlOutput]);
    const sqlHasChanges = useMemo(
        () => sqlDiffRows.some((row) => row.type !== 'same'),
        [sqlDiffRows],
    );
    const sqlDiffLines = useMemo<SqlDiffLine[]>(() => {
        let leftLine = 1;
        let rightLine = 1;
        return sqlDiffRows.map((row, index) => {
            const line: SqlDiffLine = {
                ...row,
                key: `${row.type}-${index}`,
            };
            if (row.left !== undefined) {
                line.leftNo = leftLine;
                leftLine += 1;
            }
            if (row.right !== undefined) {
                line.rightNo = rightLine;
                rightLine += 1;
            }
            return line;
        });
    }, [sqlDiffRows]);
    const manualMatchCount = manualCompare
        ? manualCompare.rows.filter((row) => row.match).length
        : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Config Compare" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Actions</CardTitle>
                        <CardDescription>
                            Comparez des configurations ou transformez des requetes SQL, puis suivez tout dans un tableau.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-wrap items-center gap-3">
                        <Dialog open={compareOpen} onOpenChange={setCompareOpen}>
                            <DialogTrigger asChild>
                                <Button type="button">Comparer des configurations</Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-2xl">
                                <DialogHeader>
                                    <DialogTitle>Comparer des configurations</DialogTitle>
                                    <DialogDescription>
                                        Choisissez deux configurations ou televersez des fichiers pour comparer.
                                    </DialogDescription>
                                </DialogHeader>
                                <form onSubmit={submitCompare} className="space-y-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="left_key">Config source</Label>
                                            <Select
                                                value={compareForm.data.left_key}
                                                onValueChange={(value) => compareForm.setData('left_key', value)}
                                            >
                                                <SelectTrigger id="left_key">
                                                    <SelectValue placeholder="Choisir une config" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {reportList.map((report) => (
                                                        <SelectItem key={report.key} value={report.key}>
                                                            {report.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={compareForm.errors.left_key} />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="right_key">Config cible</Label>
                                            <Select
                                                value={compareForm.data.right_key}
                                                onValueChange={(value) => compareForm.setData('right_key', value)}
                                            >
                                                <SelectTrigger id="right_key">
                                                    <SelectValue placeholder="Choisir une config" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {reportList.map((report) => (
                                                        <SelectItem key={report.key} value={report.key}>
                                                            {report.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={compareForm.errors.right_key} />
                                        </div>
                                    </div>

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="left_file">Fichier source (optionnel)</Label>
                                            <Input
                                                id="left_file"
                                                type="file"
                                                accept=".xlsx"
                                                onChange={(event) =>
                                                    compareForm.setData(
                                                        'left_file',
                                                        event.target.files?.[0] ?? null,
                                                    )
                                                }
                                            />
                                            <InputError message={compareForm.errors.left_file} />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="right_file">Fichier cible (optionnel)</Label>
                                            <Input
                                                id="right_file"
                                                type="file"
                                                accept=".xlsx"
                                                onChange={(event) =>
                                                    compareForm.setData(
                                                        'right_file',
                                                        event.target.files?.[0] ?? null,
                                                    )
                                                }
                                            />
                                            <InputError message={compareForm.errors.right_file} />
                                        </div>
                                    </div>

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="row_scan_limit">Limite de lignes (optionnel)</Label>
                                            <Input
                                                id="row_scan_limit"
                                                type="number"
                                                min={10}
                                                max={200}
                                                value={compareForm.data.row_scan_limit}
                                                onChange={(event) =>
                                                    compareForm.setData('row_scan_limit', event.target.value)
                                                }
                                            />
                                            <InputError message={compareForm.errors.row_scan_limit} />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="sheet_suffix">Suffixe de feuille (optionnel)</Label>
                                            <Input
                                                id="sheet_suffix"
                                                value={compareForm.data.sheet_suffix}
                                                onChange={(event) =>
                                                    compareForm.setData('sheet_suffix', event.target.value)
                                                }
                                                placeholder="_c"
                                            />
                                            <InputError message={compareForm.errors.sheet_suffix} />
                                        </div>
                                    </div>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button type="button" variant="outline">
                                                Annuler
                                            </Button>
                                        </DialogClose>
                                        <Button type="submit" disabled={compareForm.processing}>
                                            {compareForm.processing ? (
                                                <>
                                                    <Spinner className="mr-2" />
                                                    Comparaison...
                                                </>
                                            ) : (
                                                'Lancer la comparaison'
                                            )}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>

                        <Dialog open={manualOpen} onOpenChange={setManualOpen}>
                            <DialogTrigger asChild>
                                <Button type="button">Comparer tables</Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-4xl">
                                <DialogHeader>
                                    <DialogTitle>Comparaison de tables</DialogTitle>
                                    <DialogDescription>
                                        Renseignez les tables et les champs a aligner pour comparaison.
                                    </DialogDescription>
                                </DialogHeader>
                                <form onSubmit={submitManualCompare} className="space-y-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="manual-dev2-table">Table DEV2</Label>
                                            <Input
                                                id="manual-dev2-table"
                                                value={manualForm.dev2Table}
                                                onChange={(event) =>
                                                    setManualForm((prev) => ({
                                                        ...prev,
                                                        dev2Table: event.target.value,
                                                    }))
                                                }
                                                placeholder="Ex: HZ_REF_ENTITIES"
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="manual-test-table">Table TEST</Label>
                                            <Input
                                                id="manual-test-table"
                                                value={manualForm.testTable}
                                                onChange={(event) =>
                                                    setManualForm((prev) => ({
                                                        ...prev,
                                                        testTable: event.target.value,
                                                    }))
                                                }
                                                placeholder="Ex: HZ_REF_ENTITIES"
                                                required
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="manual-dev2-fields">Champs DEV2</Label>
                                            <textarea
                                                id="manual-dev2-fields"
                                                value={manualForm.dev2Fields}
                                                onChange={(event) =>
                                                    setManualForm((prev) => ({
                                                        ...prev,
                                                        dev2Fields: event.target.value,
                                                    }))
                                                }
                                                className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 min-h-[160px] w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px]"
                                                placeholder="Un champ par ligne ou separe par virgule"
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="manual-test-fields">Champs TEST</Label>
                                            <textarea
                                                id="manual-test-fields"
                                                value={manualForm.testFields}
                                                onChange={(event) =>
                                                    setManualForm((prev) => ({
                                                        ...prev,
                                                        testFields: event.target.value,
                                                    }))
                                                }
                                                className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 min-h-[160px] w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px]"
                                                placeholder="Un champ par ligne ou separe par virgule"
                                                required
                                            />
                                        </div>
                                    </div>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button type="button" variant="outline">
                                                Annuler
                                            </Button>
                                        </DialogClose>
                                        <Button type="submit">Comparer</Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>

                        <Dialog open={sqlOpen} onOpenChange={setSqlOpen}>
                            <DialogTrigger asChild>
                                <Button type="button">Transformer une requete SQL</Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-3xl">
                                <DialogHeader>
                                    <DialogTitle>Transformation de requete SQL</DialogTitle>
                                    <DialogDescription>
                                        Collez une requete SQL a transformer avec les derniers mappings.
                                    </DialogDescription>
                                </DialogHeader>
                                <form onSubmit={submitTransform} className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Nom du SQL</Label>
                                        <Input
                                            id="name"
                                            value={sqlForm.data.name}
                                            onChange={(event) => sqlForm.setData('name', event.target.value)}
                                            placeholder="Ex: Dette - Rapport 01"
                                            required
                                        />
                                        <InputError message={sqlForm.errors.name} />
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-3">
                                        <div className="space-y-2 md:col-span-1">
                                            <Label htmlFor="direction">Direction</Label>
                                            <Select
                                                value={sqlForm.data.direction}
                                                onValueChange={(value) => {
                                                    const defaults =
                                                        DEFAULT_LABELS[value as keyof typeof DEFAULT_LABELS];
                                                    if (defaults) {
                                                        sqlForm.setData({
                                                            ...sqlForm.data,
                                                            direction: value,
                                                            source_label: defaults.from,
                                                            target_label: defaults.to,
                                                        });
                                                        return;
                                                    }
                                                    sqlForm.setData('direction', value);
                                                }}
                                            >
                                                <SelectTrigger id="direction">
                                                    <SelectValue placeholder="Choisir direction" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="dev2_to_test">DEV2 to TEST</SelectItem>
                                                    <SelectItem value="test_to_dev2">TEST to DEV2</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <InputError message={sqlForm.errors.direction} />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="source_label">Label source (optionnel)</Label>
                                            <Input
                                                id="source_label"
                                                value={sqlForm.data.source_label}
                                                onChange={(event) =>
                                                    sqlForm.setData('source_label', event.target.value)
                                                }
                                                placeholder="DEV2"
                                            />
                                            <InputError message={sqlForm.errors.source_label} />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="target_label">Label cible (optionnel)</Label>
                                            <Input
                                                id="target_label"
                                                value={sqlForm.data.target_label}
                                                onChange={(event) =>
                                                    sqlForm.setData('target_label', event.target.value)
                                                }
                                                placeholder="TEST"
                                            />
                                            <InputError message={sqlForm.errors.target_label} />
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="sql">Requete source</Label>
                                        <textarea
                                            id="sql"
                                            name="sql"
                                            value={sqlForm.data.sql}
                                            onChange={(event) => sqlForm.setData('sql', event.target.value)}
                                            className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 min-h-[180px] w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px]"
                                            placeholder="select * from HZ_REF_ENTITIES"
                                        />
                                        <InputError message={sqlForm.errors.sql} />
                                    </div>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button type="button" variant="outline">
                                                Annuler
                                            </Button>
                                        </DialogClose>
                                        <Button type="submit" disabled={sqlForm.processing}>
                                            {sqlForm.processing ? (
                                                <>
                                                    <Spinner className="mr-2" />
                                                    Transformation...
                                                </>
                                            ) : (
                                                'Transformer'
                                            )}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </CardContent>
                </Card>
                {manualCompare && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Comparaison de tables</CardTitle>
                            <CardDescription>
                                DEV2: {manualCompare.dev2Table || 'DEV2'} | TEST:{' '}
                                {manualCompare.testTable || 'TEST'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex flex-wrap items-center gap-3 text-sm">
                                <Badge variant="secondary">
                                    Correspondance: {manualMatchCount}/{manualCompare.rows.length}
                                </Badge>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setManualCompare(null)}
                                >
                                    Effacer
                                </Button>
                            </div>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="overflow-auto rounded-md border">
                                    <table className="min-w-full text-xs">
                                        <thead className="bg-muted/40 text-left">
                                            <tr>
                                                <th className="px-3 py-2 font-medium">#</th>
                                                <th className="px-3 py-2 font-medium">
                                                    DEV2 {manualCompare.dev2Table || ''}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {manualCompare.rows.map((row) => (
                                                <tr
                                                    key={`dev2-${row.index}`}
                                                    className={`border-t ${
                                                        row.match ? '' : 'bg-amber-500/10'
                                                    }`}
                                                >
                                                    <td className="px-3 py-2 text-muted-foreground">
                                                        {row.index}
                                                    </td>
                                                    <td className="px-3 py-2">{row.dev2Field || '-'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="overflow-auto rounded-md border">
                                    <table className="min-w-full text-xs">
                                        <thead className="bg-muted/40 text-left">
                                            <tr>
                                                <th className="px-3 py-2 font-medium">#</th>
                                                <th className="px-3 py-2 font-medium">
                                                    TEST {manualCompare.testTable || ''}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {manualCompare.rows.map((row) => (
                                                <tr
                                                    key={`test-${row.index}`}
                                                    className={`border-t ${
                                                        row.match ? '' : 'bg-amber-500/10'
                                                    }`}
                                                >
                                                    <td className="px-3 py-2 text-muted-foreground">
                                                        {row.index}
                                                    </td>
                                                    <td className="px-3 py-2">{row.testField || '-'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}
                <Card>
                    <CardHeader>
                        <CardTitle>Historique</CardTitle>
                        <CardDescription>Toutes les comparaisons et transformations SQL sont listees ici.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 md:grid-cols-3">
                            <div className="md:col-span-2">
                                <Label htmlFor="history-filter">Recherche</Label>
                                <Input
                                    id="history-filter"
                                    value={filterText}
                                    onChange={(event) => setFilterText(event.target.value)}
                                    placeholder="Recherche par nom, type, ou SQL"
                                />
                            </div>
                            <div className="flex items-end text-xs text-muted-foreground">
                                {sortedHistory.length} entrees
                            </div>
                        </div>

                        {sortedHistory.length === 0 ? (
                            <div className="rounded-md border bg-muted/30 p-6 text-center text-sm text-muted-foreground">
                                Aucun resultat. Utilisez les actions ci-dessus pour commencer.
                            </div>
                        ) : (
                            <div className="overflow-auto rounded-md border">
                                <table className="min-w-full text-xs">
                                    <thead className="bg-muted/40 text-left">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">
                                                <button type="button" onClick={() => handleSort('type')}>
                                                    Type {sortKey === 'type' ? (sortDir === 'asc' ? '^' : 'v') : ''}
                                                </button>
                                            </th>
                                            <th className="px-3 py-2 font-medium">
                                                <button type="button" onClick={() => handleSort('name')}>
                                                    Nom {sortKey === 'name' ? (sortDir === 'asc' ? '^' : 'v') : ''}
                                                </button>
                                            </th>
                                            <th className="px-3 py-2 font-medium">
                                                <button type="button" onClick={() => handleSort('date')}>
                                                    Date {sortKey === 'date' ? (sortDir === 'asc' ? '^' : 'v') : ''}
                                                </button>
                                            </th>
                                            <th className="px-3 py-2 font-medium">
                                                <button type="button" onClick={() => handleSort('status')}>
                                                    Statut {sortKey === 'status' ? (sortDir === 'asc' ? '^' : 'v') : ''}
                                                </button>
                                            </th>
                                            <th className="px-3 py-2 font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {sortedHistory.map((entry) => {
                                            const payload = entry.payload as Record<string, unknown>;
                                            const outputSql = String(payload.output_sql ?? '');
                                            const inputSql = String(payload.input_sql ?? '');
                                            return (
                                                <tr key={entry.id} className="border-t align-top">
                                                    <td className="px-3 py-2">
                                                        <Badge variant="secondary">
                                                            {entry.type === 'config' ? 'Config' : 'SQL'}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-3 py-2 font-medium">{entry.name}</td>
                                                    <td className="px-3 py-2 text-muted-foreground">
                                                        {formatDate(entry.created_at)}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Badge variant={getStatusBadge(entry.status)}>
                                                            {getStatusLabel(entry.status)}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <div className="flex flex-wrap gap-2">
                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                onClick={() => openDetail(entry)}
                                                            >
                                                                Voir
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                onClick={() => openDiff(entry)}
                                                            >
                                                                Diff
                                                            </Button>
                                                            {entry.type === 'sql' && (
                                                                <>
                                                                    <Button
                                                                        size="sm"
                                                                        variant="secondary"
                                                                        onClick={() =>
                                                                            copyText(outputSql, 'SQL transforme')
                                                                        }
                                                                    >
                                                                        Copier
                                                                    </Button>
                                                                    <Button
                                                                        size="sm"
                                                                        variant="secondary"
                                                                        onClick={() => handleReuse(entry)}
                                                                    >
                                                                        Reutiliser
                                                                    </Button>
                                                                </>
                                                            )}
                                                            {entry.type === 'sql' && outputSql === '' && inputSql !== '' && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="secondary"
                                                                    onClick={() => copyText(inputSql, 'SQL source')}
                                                                >
                                                                    Copier source
                                                                </Button>
                                                            )}
                                                            <Button
                                                                size="sm"
                                                                variant="destructive"
                                                                onClick={() => handleDelete(entry)}
                                                            >
                                                                Supprimer
                                                            </Button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {copyMessage && <div className="text-xs text-muted-foreground">{copyMessage}</div>}
                    </CardContent>
                </Card>
                <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
                    <DialogContent className="sm:max-w-4xl">
                        <DialogHeader>
                            <DialogTitle>Details</DialogTitle>
                            <DialogDescription>
                                Consultez la comparaison ou la transformation SQL selectionnee.
                            </DialogDescription>
                        </DialogHeader>
                        {selectedEntry ? (
                            selectedEntry.type === 'sql' ? (
                                <div className="space-y-4 text-sm">
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div>
                                            <div className="text-xs font-semibold text-muted-foreground">Nom</div>
                                            <div className="text-sm">
                                                {String(
                                                    (selectedEntry.payload as Record<string, unknown>).name ??
                                                        selectedEntry.name,
                                                )}
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-xs font-semibold text-muted-foreground">Source</div>
                                            <div className="text-sm">
                                                {String(
                                                    (selectedEntry.payload as Record<string, unknown>).source_label ??
                                                        'DEV2',
                                                )}
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-xs font-semibold text-muted-foreground">Cible</div>
                                            <div className="text-sm">
                                                {String(
                                                    (selectedEntry.payload as Record<string, unknown>).target_label ??
                                                        'TEST',
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    {sqlIssues.length > 0 && (
                                        <div className="rounded-md border border-rose-500/40 bg-rose-500/10 p-3 text-xs">
                                            <div className="mb-2 text-xs font-semibold text-rose-900">
                                                Issues detectees
                                            </div>
                                            <div className="space-y-1">
                                                {sqlIssues.map((issue, index) => (
                                                    <div key={`issue-${index}`}>
                                                        {formatIssue(issue)}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                    <div className="grid gap-4 lg:grid-cols-2">
                                        <div>
                                            <div className="text-xs font-semibold text-muted-foreground">SQL source</div>
                                            <pre className="max-h-64 overflow-auto rounded-md border bg-muted/40 p-3 text-xs">
                                                {String(
                                                    (selectedEntry.payload as Record<string, unknown>).input_sql ?? '',
                                                )}
                                            </pre>
                                        </div>
                                        <div>
                                            <div className="text-xs font-semibold text-muted-foreground">SQL transforme</div>
                                            <pre className="max-h-64 overflow-auto rounded-md border bg-muted/40 p-3 text-xs">
                                                {String(
                                                    (selectedEntry.payload as Record<string, unknown>).output_sql ?? '',
                                                )}
                                            </pre>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-4 text-sm">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <div className="text-xs font-semibold text-muted-foreground">Gauche</div>
                                            <div className="text-sm">
                                                {String((selectedEntry.payload.left as any)?.label ?? 'Gauche')}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {String((selectedEntry.payload.left as any)?.count ?? 0)} objets
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-xs font-semibold text-muted-foreground">Droite</div>
                                            <div className="text-sm">
                                                {String((selectedEntry.payload.right as any)?.label ?? 'Droite')}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {String((selectedEntry.payload.right as any)?.count ?? 0)} objets
                                            </div>
                                        </div>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-4">
                                        <div className="rounded-md border bg-muted/30 p-3">
                                            <div className="text-xs text-muted-foreground">Correspondance stricte</div>
                                            <div className="text-sm">{strictOk ? 'OK' : 'Non'}</div>
                                        </div>
                                        <div className="rounded-md border bg-muted/30 p-3">
                                            <div className="text-xs text-muted-foreground">Uniquement gauche</div>
                                            <div className="text-sm">
                                                {diffLeftOnly.length}
                                            </div>
                                        </div>
                                        <div className="rounded-md border bg-muted/30 p-3">
                                            <div className="text-xs text-muted-foreground">Uniquement droite</div>
                                            <div className="text-sm">
                                                {diffRightOnly.length}
                                            </div>
                                        </div>
                                        <div className="rounded-md border bg-muted/30 p-3">
                                            <div className="text-xs text-muted-foreground">Changements</div>
                                            <div className="text-sm">{diffChanges.length}</div>
                                        </div>
                                    </div>
                                </div>
                            )
                        ) : (
                            <div className="text-sm text-muted-foreground">Aucune entree selectionnee.</div>
                        )}
                    </DialogContent>
                </Dialog>

                <Dialog open={diffOpen} onOpenChange={setDiffOpen}>
                    <DialogContent className="sm:max-w-5xl max-h-[90vh] overflow-hidden">
                        <DialogHeader>
                            <DialogTitle>{selectedEntry?.type === 'sql' ? 'Diff SQL' : 'Differences'}</DialogTitle>
                            <DialogDescription>
                                {selectedEntry?.type === 'sql'
                                    ? 'Comparaison du SQL source et du SQL transforme.'
                                    : 'Changements mis en evidence entre configurations.'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="max-h-[70vh] overflow-auto pr-2">
                            {selectedEntry ? (
                                selectedEntry.type === 'config' ? (
                                    <div className="space-y-4">
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="rounded-md border bg-muted/20 p-3 text-sm">
                                                <div className="mb-2 text-xs font-semibold text-muted-foreground">
                                                    Uniquement gauche
                                                </div>
                                                {diffLeftOnly.length === 0 ? (
                                                    <div className="text-xs text-muted-foreground">Aucun</div>
                                                ) : (
                                                    <div className="flex flex-wrap gap-2">
                                                        {diffLeftOnly.map((item: string) => (
                                                            <Badge key={item} variant="secondary">
                                                                {item}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                            <div className="rounded-md border bg-muted/20 p-3 text-sm">
                                                <div className="mb-2 text-xs font-semibold text-muted-foreground">
                                                    Uniquement droite
                                                </div>
                                                {diffRightOnly.length === 0 ? (
                                                    <div className="text-xs text-muted-foreground">Aucun</div>
                                                ) : (
                                                    <div className="flex flex-wrap gap-2">
                                                        {diffRightOnly.map((item: string) => (
                                                            <Badge key={item} variant="secondary">
                                                                {item}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        {diffChanges.length === 0 ? (
                                            <div className="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground">
                                                Aucune difference detectee.
                                            </div>
                                        ) : (
                                            <div className="overflow-auto rounded-md border">
                                                <table className="min-w-full text-xs">
                                                    <thead className="bg-muted/40 text-left">
                                                        <tr>
                                                            <th className="px-3 py-2 font-medium">Objet</th>
                                                            <th className="px-3 py-2 font-medium">Table gauche</th>
                                                            <th className="px-3 py-2 font-medium">Table droite</th>
                                                            <th className="px-3 py-2 font-medium">Champs gauche</th>
                                                            <th className="px-3 py-2 font-medium">Champs droite</th>
                                                            <th className="px-3 py-2 font-medium">Diff</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {diffChanges.map((change: any) => {
                                                            const tableChanged = Boolean(change.table_changed);
                                                            const fieldsDiff = change.custom_fields_diff;
                                                            const highlight = tableChanged || fieldsDiff;
                                                            return (
                                                                <tr
                                                                    key={change.object}
                                                                    className={`border-t ${
                                                                        highlight ? 'bg-amber-500/10' : ''
                                                                    }`}
                                                                >
                                                                    <td className="px-3 py-2 font-medium">
                                                                        {change.object}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {change.left_table ?? 'n/a'}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {change.right_table ?? 'n/a'}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {change.left_custom_fields ?? 'n/a'}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {change.right_custom_fields ?? 'n/a'}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {fieldsDiff ?? 'n/a'}
                                                                    </td>
                                                                </tr>
                                                            );
                                                        })}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <div className="space-y-4">
                                        {sqlDiffLines.length === 0 || !sqlHasChanges ? (
                                            <div className="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground">
                                                Aucune difference detectee.
                                            </div>
                                        ) : (
                                            <div className="overflow-auto rounded-md border">
                                                <table className="min-w-full text-xs font-mono">
                                                    <thead className="bg-muted/40 text-left">
                                                        <tr>
                                                            <th className="px-3 py-2 font-medium">#</th>
                                                            <th className="px-3 py-2 font-medium">SQL source</th>
                                                            <th className="px-3 py-2 font-medium">#</th>
                                                            <th className="px-3 py-2 font-medium">SQL transforme</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {sqlDiffLines.map((row) => (
                                                            <tr key={row.key} className="border-t align-top">
                                                                <td className="px-3 py-2 text-muted-foreground">
                                                                    {row.leftNo ?? ''}
                                                                </td>
                                                                <td
                                                                    className={`px-3 py-2 whitespace-pre ${
                                                                        row.type === 'remove' ? 'bg-rose-500/10' : ''
                                                                    }`}
                                                                >
                                                                    {row.left ?? ''}
                                                                </td>
                                                                <td className="px-3 py-2 text-muted-foreground">
                                                                    {row.rightNo ?? ''}
                                                                </td>
                                                                <td
                                                                    className={`px-3 py-2 whitespace-pre ${
                                                                        row.type === 'add' ? 'bg-emerald-500/10' : ''
                                                                    }`}
                                                                >
                                                                    {row.right ?? ''}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </div>
                                )
                            ) : (
                                <div className="text-sm text-muted-foreground">Aucune donnee de diff.</div>
                            )}
                        </div>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
