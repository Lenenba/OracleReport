import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import {
    index as configCompareIndex,
    save as configCompareSave,
    transform as configCompareTransform,
} from '@/routes/config-compare';
import { type BreadcrumbItem } from '@/types';

type ReportSource = {
    key: string;
    label: string;
    path: string | null;
    exists: boolean;
    mtime: string | null;
};

type ObjectEntry = {
    object: string;
    table_name: string | null;
    custom_fields: number | null;
};

type ChangeEntry = {
    object: string;
    dev_table: string | null;
    test_table: string | null;
    dev_custom_fields: number | null;
    test_custom_fields: number | null;
    table_changed: boolean;
    custom_fields_diff: number | null;
};

type Mapping = {
    dev2_to_test: Record<string, string>;
    test_to_dev2: Record<string, string>;
};

type Conflict = {
    from: string;
    targets: string[];
    objects: string[];
};

type Comparison = {
    objects: {
        dev2: ObjectEntry[];
        test: ObjectEntry[];
    };
    only_in_dev2: string[];
    only_in_test: string[];
    changes: ChangeEntry[];
    mapping: Mapping;
    conflicts: {
        dev2_to_test: Conflict[];
        test_to_dev2: Conflict[];
    };
    errors: {
        dev2: string[];
        test: string[];
    };
};

type TransformResult = {
    input: string;
    output: string;
    replacements: Array<{ from: string; to: string; count: number }>;
    error: string | null;
};

type SavedEntry = {
    id: number;
    created_at: string;
    direction: 'dev2_to_test' | 'test_to_dev2';
    source_label: string;
    target_label: string;
    input_sql: string;
    output_sql: string;
    replacements: Array<{ from: string; to: string; count: number }>;
};

type PageProps = {
    reports: Record<string, ReportSource>;
    comparison: Comparison;
    saved_entries: SavedEntry[];
    input?: {
        direction: string;
        sql: string;
    };
    transform?: TransformResult;
    notice?: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Config Compare',
        href: configCompareIndex().url,
    },
];

const formatDiff = (value: number | null) => {
    if (value === null || value === undefined) {
        return 'n/a';
    }
    if (value === 0) {
        return '0';
    }
    return value > 0 ? `+${value}` : String(value);
};

const DEFAULT_LABELS = {
    dev2_to_test: { from: 'DEV2', to: 'TEST' },
    test_to_dev2: { from: 'TEST', to: 'DEV2' },
} as const;

export default function ConfigCompare() {
    const { props } = usePage<PageProps>();
    const comparison = props.comparison;
    const reportList = Object.values(props.reports ?? {});
    const onlyInDev2 = comparison?.only_in_dev2 ?? [];
    const onlyInTest = comparison?.only_in_test ?? [];
    const changes = comparison?.changes ?? [];
    const dev2Objects = comparison?.objects?.dev2 ?? [];
    const testObjects = comparison?.objects?.test ?? [];
    const transformResult = props.transform;
    const [copyMessage, setCopyMessage] = useState<string | null>(props.notice ?? null);
    const [filterText, setFilterText] = useState('');

    const mappingCounts = {
        dev2_to_test: Object.keys(comparison?.mapping?.dev2_to_test ?? {}).length,
        test_to_dev2: Object.keys(comparison?.mapping?.test_to_dev2 ?? {}).length,
    };
    const conflictCounts = {
        dev2_to_test: comparison?.conflicts?.dev2_to_test?.length ?? 0,
        test_to_dev2: comparison?.conflicts?.test_to_dev2?.length ?? 0,
    };

    const form = useForm({
        direction: props.input?.direction ?? 'dev2_to_test',
        sql: props.input?.sql ?? '',
    });

    const [saveSourceLabel, setSaveSourceLabel] = useState(
        DEFAULT_LABELS[form.data.direction as keyof typeof DEFAULT_LABELS]?.from ?? 'DEV2',
    );
    const [saveTargetLabel, setSaveTargetLabel] = useState(
        DEFAULT_LABELS[form.data.direction as keyof typeof DEFAULT_LABELS]?.to ?? 'TEST',
    );

    useEffect(() => {
        if (!props.notice) {
            return undefined;
        }
        const timer = setTimeout(() => setCopyMessage(null), 2000);
        return () => clearTimeout(timer);
    }, [props.notice]);

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        form.post(configCompareTransform().url, {
            preserveScroll: true,
        });
    };

    const copyText = async (label: string, value: string) => {
        if (!value) {
            setCopyMessage('Nothing to copy.');
            return;
        }
        try {
            if (navigator?.clipboard?.writeText) {
                await navigator.clipboard.writeText(value);
                setCopyMessage(`${label} copied.`);
            } else if (typeof document !== 'undefined') {
                const textarea = document.createElement('textarea');
                textarea.value = value;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'absolute';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                setCopyMessage(`${label} copied.`);
            }
        } catch {
            setCopyMessage('Copy failed.');
        } finally {
            setTimeout(() => setCopyMessage(null), 2000);
        }
    };

    const saveForm = useForm({
        direction: form.data.direction,
        source_label: saveSourceLabel,
        target_label: saveTargetLabel,
        input_sql: '',
        output_sql: '',
        replacements: [] as Array<{ from: string; to: string; count: number }>,
    });

    useEffect(() => {
        if (!transformResult) {
            saveForm.setData({
                direction: form.data.direction,
                source_label: saveSourceLabel,
                target_label: saveTargetLabel,
                input_sql: '',
                output_sql: '',
                replacements: [],
            });
            return;
        }
        const direction = (form.data.direction || 'dev2_to_test') as SavedEntry['direction'];
        saveForm.setData({
            direction,
            source_label: saveSourceLabel.trim() || DEFAULT_LABELS[direction].from,
            target_label: saveTargetLabel.trim() || DEFAULT_LABELS[direction].to,
            input_sql: transformResult.input,
            output_sql: transformResult.output,
            replacements: transformResult.replacements ?? [],
        });
    }, [form.data.direction, saveSourceLabel, saveTargetLabel, transformResult]);

    const handleSave = () => {
        if (!transformResult) {
            setCopyMessage('Nothing to save.');
            return;
        }
        saveForm.post(configCompareSave().url, {
            preserveScroll: true,
        });
    };

    const handleLoadSaved = (entry: SavedEntry) => {
        form.setData({
            direction: entry.direction,
            sql: entry.input_sql,
        });
        setSaveSourceLabel(entry.source_label);
        setSaveTargetLabel(entry.target_label);
    };

    const filteredEntries = useMemo(() => {
        const entries = props.saved_entries ?? [];
        const needle = filterText.trim().toLowerCase();
        if (needle === '') {
            return entries;
        }
        return entries.filter((entry) => {
            const haystack = [
                entry.direction,
                entry.source_label,
                entry.target_label,
                entry.input_sql,
                entry.output_sql,
            ]
                .join(' ')
                .toLowerCase();
            return haystack.includes(needle);
        });
    }, [filterText, props.saved_entries]);

    const selectedMappingCount = form.data.direction === 'test_to_dev2'
        ? mappingCounts.test_to_dev2
        : mappingCounts.dev2_to_test;
    const selectedConflictCount = form.data.direction === 'test_to_dev2'
        ? conflictCounts.test_to_dev2
        : conflictCounts.dev2_to_test;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Config Compare" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Config reports</CardTitle>
                        <CardDescription>
                            Compare the Fusion Configuration Report exports between environments.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2">
                        {reportList.map((report) => {
                            const reportErrors = comparison?.errors?.[report.key as 'dev2' | 'test'] ?? [];
                            return (
                                <div key={report.key} className="rounded-md border bg-muted/20 p-4 text-sm">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="font-medium">{report.label}</div>
                                            <div className="text-xs text-muted-foreground break-all">
                                                {report.path ?? 'No path configured'}
                                            </div>
                                        </div>
                                        <Badge variant={report.exists ? 'secondary' : 'destructive'}>
                                            {report.exists ? 'FOUND' : 'MISSING'}
                                        </Badge>
                                    </div>
                                    <div className="mt-2 text-xs text-muted-foreground">
                                        {report.mtime ? `Updated ${report.mtime}` : 'No file timestamp'}
                                    </div>
                                    {reportErrors.length > 0 && (
                                        <div className="mt-2 rounded-md border border-destructive/40 bg-destructive/10 p-2 text-xs text-destructive">
                                            {reportErrors.join(' ')}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Comparison summary</CardTitle>
                        <CardDescription>Counts of objects and differences.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-3 sm:grid-cols-4">
                            <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2 text-sm">
                                <span className="text-muted-foreground">DEV2 objects</span>
                                <span>{dev2Objects.length}</span>
                            </div>
                            <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2 text-sm">
                                <span className="text-muted-foreground">TEST objects</span>
                                <span>{testObjects.length}</span>
                            </div>
                            <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2 text-sm">
                                <span className="text-muted-foreground">Table changes</span>
                                <span>{changes.length}</span>
                            </div>
                            <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2 text-sm">
                                <span className="text-muted-foreground">Only in one env</span>
                                <span>{onlyInDev2.length + onlyInTest.length}</span>
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="rounded-md border bg-muted/20 p-4 text-sm">
                                <div className="mb-2 font-medium">Only in DEV2</div>
                                {onlyInDev2.length === 0 ? (
                                    <div className="text-xs text-muted-foreground">None</div>
                                ) : (
                                    <div className="flex max-h-32 flex-wrap gap-2 overflow-auto">
                                        {onlyInDev2.map((name) => (
                                            <Badge key={name} variant="secondary">
                                                {name}
                                            </Badge>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div className="rounded-md border bg-muted/20 p-4 text-sm">
                                <div className="mb-2 font-medium">Only in TEST</div>
                                {onlyInTest.length === 0 ? (
                                    <div className="text-xs text-muted-foreground">None</div>
                                ) : (
                                    <div className="flex max-h-32 flex-wrap gap-2 overflow-auto">
                                        {onlyInTest.map((name) => (
                                            <Badge key={name} variant="secondary">
                                                {name}
                                            </Badge>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Table changes</CardTitle>
                        <CardDescription>Objects with table or custom field count differences.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {changes.length === 0 ? (
                            <div className="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground">
                                No differences detected.
                            </div>
                        ) : (
                            <div className="overflow-auto rounded-md border">
                                <table className="min-w-full text-xs">
                                    <thead className="bg-muted/40 text-left">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">Object</th>
                                            <th className="px-3 py-2 font-medium">DEV2 table</th>
                                            <th className="px-3 py-2 font-medium">TEST table</th>
                                            <th className="px-3 py-2 font-medium">DEV2 fields</th>
                                            <th className="px-3 py-2 font-medium">TEST fields</th>
                                            <th className="px-3 py-2 font-medium">Diff</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {changes.map((change) => (
                                            <tr key={change.object} className="border-t">
                                                <td className="px-3 py-2 font-medium">{change.object}</td>
                                                <td className="px-3 py-2">
                                                    {change.dev_table ?? 'n/a'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {change.test_table ?? 'n/a'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {change.dev_custom_fields ?? 'n/a'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {change.test_custom_fields ?? 'n/a'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {formatDiff(change.custom_fields_diff)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>SQL transformer</CardTitle>
                        <CardDescription>
                            Replace table names using the mapping from the config reports.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="grid gap-6 lg:grid-cols-3">
                            <div className="space-y-2">
                                <Label htmlFor="direction">Direction</Label>
                                <Select
                                    value={form.data.direction}
                                    onValueChange={(value) => {
                                        form.setData('direction', value);
                                        const defaults =
                                            DEFAULT_LABELS[value as keyof typeof DEFAULT_LABELS];
                                        if (defaults) {
                                            setSaveSourceLabel(defaults.from);
                                            setSaveTargetLabel(defaults.to);
                                        }
                                    }}
                                >
                                    <SelectTrigger id="direction">
                                        <SelectValue placeholder="Select direction" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="dev2_to_test">DEV2 to TEST</SelectItem>
                                        <SelectItem value="test_to_dev2">TEST to DEV2</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.direction} />
                            </div>

                            <div className="space-y-2 lg:col-span-2">
                                <Label>Mapping coverage</Label>
                                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    <Badge variant="secondary">{selectedMappingCount} mappings</Badge>
                                    <Badge variant={selectedConflictCount > 0 ? 'destructive' : 'secondary'}>
                                        {selectedConflictCount} conflicts
                                    </Badge>
                                </div>
                                {selectedConflictCount > 0 && (
                                    <div className="text-xs text-muted-foreground">
                                        Conflicts are skipped in the replacement list.
                                    </div>
                                )}
                            </div>

                            <div className="space-y-2 lg:col-span-3">
                                <Label htmlFor="sql">SQL query</Label>
                                <textarea
                                    id="sql"
                                    name="sql"
                                    value={form.data.sql}
                                    onChange={(event) => form.setData('sql', event.target.value)}
                                    className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 min-h-[160px] w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px]"
                                    placeholder="select * from HZ_REF_ENTITIES"
                                />
                                <InputError message={form.errors.sql} />
                            </div>

                            <div className="grid gap-3 lg:col-span-2">
                                <div className="text-sm font-medium">Save labels</div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="save-source-label">Source label</Label>
                                        <Input
                                            id="save-source-label"
                                            value={saveSourceLabel}
                                            onChange={(event) => setSaveSourceLabel(event.target.value)}
                                            placeholder="DEV2"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="save-target-label">Target label</Label>
                                        <Input
                                            id="save-target-label"
                                            value={saveTargetLabel}
                                            onChange={(event) => setSaveTargetLabel(event.target.value)}
                                            placeholder="TEST"
                                        />
                                    </div>
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Labels are saved with the SQL so you can tag other environments later.
                                </div>
                            </div>

                            <div className="lg:col-span-3 flex flex-wrap items-center justify-between gap-3">
                                <div className="text-xs text-muted-foreground">
                                    Replacements are case-insensitive. Review output before running.
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button type="submit" disabled={form.processing}>
                                        {form.processing ? (
                                            <>
                                                <Spinner className="mr-2" />
                                                Transforming...
                                            </>
                                        ) : (
                                            'Transform SQL'
                                        )}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={!transformResult || saveForm.processing}
                                        onClick={handleSave}
                                    >
                                        {saveForm.processing ? 'Saving...' : 'Save output'}
                                    </Button>
                                </div>
                                {saveForm.errors.output_sql && (
                                    <InputError message={saveForm.errors.output_sql} />
                                )}
                            </div>
                        </form>

                        {transformResult && (
                            <div className="mt-6 grid gap-4 lg:grid-cols-2">
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="text-xs font-semibold text-muted-foreground">Output SQL</div>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="secondary"
                                            onClick={() => copyText('Output SQL', transformResult.output)}
                                        >
                                            Copy
                                        </Button>
                                    </div>
                                    <pre className="max-h-64 overflow-auto rounded-md border bg-muted/40 p-3 text-xs">
                                        {transformResult.output}
                                    </pre>
                                    {transformResult.error && (
                                        <div className="rounded-md border border-destructive/40 bg-destructive/10 p-2 text-xs text-destructive">
                                            {transformResult.error}
                                        </div>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <div className="text-xs font-semibold text-muted-foreground">Replacements</div>
                                    {transformResult.replacements.length === 0 ? (
                                        <div className="rounded-md border bg-muted/30 p-3 text-xs text-muted-foreground">
                                            No replacements applied.
                                        </div>
                                    ) : (
                                        <div className="overflow-auto rounded-md border">
                                            <table className="min-w-full text-xs">
                                                <thead className="bg-muted/40 text-left">
                                                    <tr>
                                                        <th className="px-3 py-2 font-medium">From</th>
                                                        <th className="px-3 py-2 font-medium">To</th>
                                                        <th className="px-3 py-2 font-medium">Count</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {transformResult.replacements.map((item) => (
                                                        <tr key={`${item.from}-${item.to}`} className="border-t">
                                                            <td className="px-3 py-2">{item.from}</td>
                                                            <td className="px-3 py-2">{item.to}</td>
                                                            <td className="px-3 py-2">{item.count}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                        {copyMessage && (
                            <div className="mt-4 text-xs text-muted-foreground">{copyMessage}</div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Saved SQL</CardTitle>
                        <CardDescription>Saved in the database for later review.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="sm:col-span-2">
                                <Label htmlFor="saved-filter">Search</Label>
                                <Input
                                    id="saved-filter"
                                    value={filterText}
                                    onChange={(event) => setFilterText(event.target.value)}
                                    placeholder="Search by label, SQL, or direction"
                                />
                            </div>
                            <div className="flex items-end text-xs text-muted-foreground">
                                {filteredEntries.length} entries
                            </div>
                        </div>

                        {filteredEntries.length === 0 ? (
                            <div className="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground">
                                No saved SQL yet.
                            </div>
                        ) : (
                            <div className="overflow-auto rounded-md border">
                                <table className="min-w-full text-xs">
                                    <thead className="bg-muted/40 text-left">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">Created</th>
                                            <th className="px-3 py-2 font-medium">Direction</th>
                                            <th className="px-3 py-2 font-medium">Source</th>
                                            <th className="px-3 py-2 font-medium">Target</th>
                                            <th className="px-3 py-2 font-medium">Source SQL</th>
                                            <th className="px-3 py-2 font-medium">Target SQL</th>
                                            <th className="px-3 py-2 font-medium">Replacements</th>
                                            <th className="px-3 py-2 font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredEntries.map((entry) => (
                                            <tr key={entry.id} className="border-t align-top">
                                                <td className="px-3 py-2 text-muted-foreground">
                                                    {entry.created_at ?? 'n/a'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <Badge variant="secondary">
                                                        {entry.direction === 'dev2_to_test'
                                                            ? 'DEV2 to TEST'
                                                            : 'TEST to DEV2'}
                                                    </Badge>
                                                </td>
                                                <td className="px-3 py-2">{entry.source_label}</td>
                                                <td className="px-3 py-2">{entry.target_label}</td>
                                                <td className="px-3 py-2 max-w-xs">
                                                    <div className="truncate">{entry.input_sql}</div>
                                                </td>
                                                <td className="px-3 py-2 max-w-xs">
                                                    <div className="truncate">{entry.output_sql}</div>
                                                </td>
                                                <td className="px-3 py-2">
                                                    {entry.replacements?.length ?? 0}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div className="flex flex-wrap gap-2">
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() => handleLoadSaved(entry)}
                                                        >
                                                            Load
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() => copyText('Source SQL', entry.input_sql)}
                                                        >
                                                            Copy source
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() => copyText('Target SQL', entry.output_sql)}
                                                        >
                                                            Copy target
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
