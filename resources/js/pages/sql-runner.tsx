import { type FormEvent } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import { index as sqlRunnerIndex, run as sqlRunnerRun } from '@/routes/sql-runner';
import { type BreadcrumbItem } from '@/types';

type EnvironmentInfo = {
    key: string;
    label: string;
    configured: boolean;
    connection?: string | null;
    report_path?: string | null;
    mode?: string;
    target?: string | null;
};

type SqlResult = {
    key: string;
    label: string;
    ok: boolean;
    target: string | null;
    duration_ms: number | null;
    row_count: number;
    truncated: boolean;
    columns: string[];
    rows: Array<Record<string, unknown>>;
    error: string | null;
};

type RunPayload = {
    query: string;
    limit: number;
    requested_at: string;
    results: Record<string, SqlResult>;
};

type Limits = {
    default_rows: number;
    max_rows: number;
    max_query_length: number;
};

type PageProps = {
    environments: Record<string, EnvironmentInfo>;
    limits: Limits;
    input?: {
        environments: string[];
        query: string;
        limit: number | null;
    };
    run?: RunPayload;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'SQL Runner',
        href: sqlRunnerIndex().url,
    },
];

const formatValue = (value: unknown) => {
    if (value === null || value === undefined) {
        return '';
    }
    if (typeof value === 'object') {
        try {
            return JSON.stringify(value);
        } catch {
            return String(value);
        }
    }
    return String(value);
};

export default function SqlRunner() {
    const { props } = usePage<PageProps>();
    const { environments, limits, input, run } = props;
    const envList = Object.values(environments ?? {});
    const defaultEnvironments = envList.filter((env) => env.configured).map((env) => env.key);

    const form = useForm<{
        environments: string[];
        query: string;
        limit: number | '';
    }>({
        environments: input?.environments ?? defaultEnvironments,
        query: input?.query ?? '',
        limit: input?.limit ?? limits.default_rows ?? 200,
    });

    const selectedEnvList = form.data.environments
        .map((key) => environments?.[key])
        .filter((env): env is EnvironmentInfo => Boolean(env));

    const runResults = run
        ? envList
              .map((env) => run.results[env.key])
              .filter((result): result is SqlResult => Boolean(result))
        : [];

    const toggleEnvironment = (key: string, enabled: boolean) => {
        const next = new Set(form.data.environments);
        if (enabled) {
            next.add(key);
        } else {
            next.delete(key);
        }
        form.setData('environments', Array.from(next));
    };

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        form.post(sqlRunnerRun().url, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SQL Runner" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>SQL runner</CardTitle>
                        <CardDescription>
                            Read-only SELECT/CTE queries. Results are fetched live for the selected environments.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="grid gap-6 lg:grid-cols-3">
                            <div className="space-y-2 lg:col-span-3">
                                <Label>Environments</Label>
                                <div className="flex flex-wrap gap-4">
                                    {envList.map((env) => {
                                        const isChecked = form.data.environments.includes(env.key);
                                        const checkboxId = `sql-env-${env.key}`;
                                        return (
                                            <label
                                                key={env.key}
                                                htmlFor={checkboxId}
                                                className="flex items-center gap-3 rounded-md border bg-muted/30 px-3 py-2 text-sm"
                                            >
                                                <Checkbox
                                                    id={checkboxId}
                                                    checked={isChecked}
                                                    disabled={!env.configured}
                                                    onCheckedChange={(value) =>
                                                        toggleEnvironment(env.key, value === true)
                                                    }
                                                />
                                                <div className="flex flex-col">
                                                    <span className="font-medium">{env.label}</span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {env.mode === 'bip'
                                                            ? env.report_path ?? 'Missing report path'
                                                            : env.connection ?? 'Missing connection'}
                                                    </span>
                                                </div>
                                                {!env.configured && (
                                                    <Badge variant="destructive">Missing config</Badge>
                                                )}
                                            </label>
                                        );
                                    })}
                                </div>
                                <InputError message={form.errors.environments} />
                            </div>

                            <div className="space-y-2 lg:col-span-2">
                                <Label htmlFor="query">SQL query</Label>
                                <textarea
                                    id="query"
                                    name="query"
                                    value={form.data.query}
                                    onChange={(event) => form.setData('query', event.target.value)}
                                    className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 min-h-[180px] w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px]"
                                    placeholder="select * from dual"
                                />
                                <div className="text-xs text-muted-foreground">
                                    Only SELECT/CTE queries. Writes are blocked. Max {limits.max_query_length}{' '}
                                    chars.
                                </div>
                                <InputError message={form.errors.query} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="limit">Row limit</Label>
                                <Input
                                    id="limit"
                                    name="limit"
                                    type="number"
                                    min={1}
                                    max={limits.max_rows}
                                    value={form.data.limit}
                                    onChange={(event) => {
                                        const raw = event.target.value;
                                        const parsed = raw === '' ? '' : Number(raw);
                                        form.setData('limit', Number.isNaN(parsed) ? '' : parsed);
                                    }}
                                />
                                <div className="text-xs text-muted-foreground">
                                    Max {limits.max_rows} rows.
                                </div>
                                <InputError message={form.errors.limit} />
                            </div>

                            <div className="lg:col-span-3 flex flex-wrap items-center justify-between gap-3">
                                <div className="text-xs text-muted-foreground">
                                    Selected envs: {selectedEnvList.map((env) => env.label).join(', ') || 'None'}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={form.processing || form.data.environments.length === 0}
                                >
                                    {form.processing ? (
                                        <>
                                            <Spinner className="mr-2" />
                                            Running...
                                        </>
                                    ) : (
                                        'Run selected'
                                    )}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {runResults.length > 0 && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        {runResults.map((result) => (
                            <Card key={result.key}>
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <CardTitle>{result.label}</CardTitle>
                                            <CardDescription>{result.target ?? 'No target'}</CardDescription>
                                        </div>
                                        <Badge variant={result.ok ? 'secondary' : 'destructive'}>
                                            {result.ok ? 'OK' : 'ERROR'}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <div className="grid gap-2">
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Duration</span>
                                            <span>
                                                {result.duration_ms !== null
                                                    ? `${result.duration_ms} ms`
                                                    : 'n/a'}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Rows</span>
                                            <span>
                                                {result.row_count}
                                                {result.truncated ? ' (truncated)' : ''}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Columns</span>
                                            <span>{result.columns.length}</span>
                                        </div>
                                    </div>

                                    {result.error && (
                                        <div className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-xs text-destructive">
                                            {result.error}
                                        </div>
                                    )}

                                    {result.rows.length === 0 && !result.error && (
                                        <div className="rounded-md border bg-muted/30 p-3 text-xs text-muted-foreground">
                                            No rows returned.
                                        </div>
                                    )}

                                    {result.rows.length > 0 && (
                                        <div className="overflow-auto rounded-md border">
                                            <table className="min-w-full text-xs">
                                                <thead className="bg-muted/40 text-left">
                                                    <tr>
                                                        {result.columns.map((column) => (
                                                            <th key={column} className="px-3 py-2 font-medium">
                                                                {column}
                                                            </th>
                                                        ))}
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {result.rows.map((row, rowIndex) => (
                                                        <tr key={rowIndex} className="border-t">
                                                            {result.columns.map((column) => (
                                                                <td key={column} className="px-3 py-2 align-top">
                                                                    {formatValue(row[column])}
                                                                </td>
                                                            ))}
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
