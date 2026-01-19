import { type FormEvent } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import { index as biPublisherIndex, run as biPublisherRun } from '@/routes/bi-publisher';
import { type BreadcrumbItem } from '@/types';

type EnvironmentInfo = {
    key: string;
    label: string;
    configured: boolean;
    base_url?: string | null;
};

type RunEnvironmentResult = {
    key: string;
    label: string;
    ok: boolean;
    status: number | null;
    duration_ms: number | null;
    content_type: string | null;
    size_bytes: number | null;
    sha256: string | null;
    preview: string | null;
    error: string | null;
    url: string | null;
    report_path?: string | null;
};

type RunComparison = {
    hash_match?: boolean;
    size_match?: boolean;
    duration_diff_ms?: number | null;
};

type RunPayload = {
    report_path: string;
    report_paths?: Record<string, string>;
    format: string;
    parameters: Record<string, unknown> | Array<Record<string, unknown>> | null;
    requested_at: string;
    results: Record<string, RunEnvironmentResult>;
    comparison: RunComparison | null;
};

type PageProps = {
    environments: Record<string, EnvironmentInfo>;
    defaults: {
        format: string;
    };
    input?: {
        environments: string[];
        report_path: string;
        report_paths?: Record<string, string>;
        format: string;
        parameters: string;
    };
    run?: RunPayload;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'BI Publisher',
        href: biPublisherIndex().url,
    },
];

const formatOptions = ['pdf', 'csv', 'xlsx', 'xml', 'html', 'text', 'rtf', 'pptx'];

export default function BiPublisher() {
    const { props } = usePage<PageProps>();
    const { environments, defaults, input, run } = props;
    const envList = Object.values(environments ?? {});
    const defaultEnvironments = envList.filter((env) => env.configured).map((env) => env.key);
    const initialData = {
        environments: input?.environments ?? defaultEnvironments,
        report_path: input?.report_path ?? '',
        report_paths: input?.report_paths ?? {},
        format: input?.format ?? defaults?.format ?? 'pdf',
        parameters: input?.parameters ?? '',
    };

    const form = useForm(initialData);
    const comparison = run?.comparison ?? null;
    const runResults = run
        ? envList
              .map((env) => run.results[env.key])
              .filter((result): result is RunEnvironmentResult => Boolean(result))
        : [];
    const selectedEnvList = form.data.environments
        .map((key) => environments?.[key])
        .filter((env): env is EnvironmentInfo => Boolean(env));

    const toggleEnvironment = (key: string, enabled: boolean) => {
        const next = new Set(form.data.environments);
        if (enabled) {
            next.add(key);
        } else {
            next.delete(key);
        }
        form.setData('environments', Array.from(next));
    };

    const updateReportPath = (envKey: string, value: string) => {
        form.setData('report_paths', {
            ...form.data.report_paths,
            [envKey]: value,
        });
    };

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        form.post(biPublisherRun().url, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="BI Publisher" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>BI Publisher runner</CardTitle>
                        <CardDescription>
                            Execute BI Publisher reports in selected Oracle Fusion environments. Results are fetched
                            live and not stored locally.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="grid gap-6 lg:grid-cols-3">
                            <div className="space-y-2 lg:col-span-3">
                                <Label>Environments</Label>
                                <div className="flex flex-wrap gap-4">
                                    {envList.map((env) => {
                                        const isChecked = form.data.environments.includes(env.key);
                                        const checkboxId = `env-${env.key}`;
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
                                                        {env.base_url ?? 'Missing base URL'}
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
                                <Label htmlFor="report_path">Default report path</Label>
                                <Input
                                    id="report_path"
                                    name="report_path"
                                    value={form.data.report_path}
                                    onChange={(event) => form.setData('report_path', event.target.value)}
                                    placeholder="/Custom/Reports/YourReport.xdo"
                                />
                                <div className="text-xs text-muted-foreground">
                                    Used when an environment path is not set.
                                </div>
                                <InputError message={form.errors.report_path} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="format">Format</Label>
                                <Select
                                    value={form.data.format}
                                    onValueChange={(value) => form.setData('format', value)}
                                >
                                    <SelectTrigger id="format">
                                        <SelectValue placeholder="Select format" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {formatOptions.map((option) => (
                                            <SelectItem key={option} value={option}>
                                                {option.toUpperCase()}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.format} />
                            </div>

                            <div className="space-y-2 lg:col-span-3">
                                <Label htmlFor="parameters">Parameters</Label>
                                <textarea
                                    id="parameters"
                                    name="parameters"
                                    value={form.data.parameters}
                                    onChange={(event) => form.setData('parameters', event.target.value)}
                                    className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 min-h-[140px] w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px]"
                                    placeholder={`P_REPORT_DATE=2024-01-01\nP_BUSINESS_UNIT=US01|US02\n\nOr JSON:\n{ \"P_REPORT_DATE\": \"2024-01-01\" }`}
                                />
                                <div className="text-xs text-muted-foreground">
                                    Use key=value per line. Separate multi-values with | or provide JSON.
                                </div>
                                <InputError message={form.errors.parameters} />
                            </div>

                            {selectedEnvList.length > 0 && (
                                <div className="space-y-3 lg:col-span-3">
                                    <div className="text-sm font-medium">Environment report paths (optional)</div>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {selectedEnvList.map((env) => {
                                            const fieldId = `report-path-${env.key}`;
                                            const errorKey = `report_paths.${env.key}` as const;
                                            return (
                                                <div key={env.key} className="space-y-2">
                                                    <Label htmlFor={fieldId}>
                                                        {env.label} report path
                                                    </Label>
                                                    <Input
                                                        id={fieldId}
                                                        name={fieldId}
                                                        value={form.data.report_paths?.[env.key] ?? ''}
                                                        onChange={(event) =>
                                                            updateReportPath(env.key, event.target.value)
                                                        }
                                                        placeholder="/Custom/Reports/YourReport.xdo"
                                                        disabled={!env.configured}
                                                    />
                                                    {form.errors[errorKey] && (
                                                        <InputError message={form.errors[errorKey]} />
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            <div className="lg:col-span-3 flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                    {envList.map((env) => (
                                        <span key={env.key} className="flex items-center gap-2">
                                            <Badge variant={env.configured ? 'secondary' : 'destructive'}>
                                                {env.label}
                                            </Badge>
                                            <span>{env.base_url ?? 'Missing base URL'}</span>
                                        </span>
                                    ))}
                                </div>
                                <Button type="submit" disabled={form.processing}>
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

                {run && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        {runResults.map((result) => (
                            <Card key={result.key}>
                                <CardHeader>
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <CardTitle>{result.label}</CardTitle>
                                            <CardDescription>
                                                <div className="truncate">
                                                    {result.report_path ?? 'No report path'}
                                                </div>
                                                <div className="truncate text-xs">
                                                    {result.url ?? 'No request sent'}
                                                </div>
                                            </CardDescription>
                                        </div>
                                        <Badge variant={result.ok ? 'secondary' : 'destructive'}>
                                            {result.ok ? 'OK' : 'ERROR'}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <div className="grid gap-2">
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Status</span>
                                            <span>{result.status ?? 'n/a'}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Duration</span>
                                            <span>
                                                {result.duration_ms !== null ? `${result.duration_ms} ms` : 'n/a'}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Content type</span>
                                            <span>{result.content_type ?? 'n/a'}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Size</span>
                                            <span>
                                                {result.size_bytes !== null ? `${result.size_bytes} bytes` : 'n/a'}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">SHA256</span>
                                            <span className="truncate text-xs">{result.sha256 ?? 'n/a'}</span>
                                        </div>
                                    </div>

                                    {result.error && (
                                        <div className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-xs text-destructive">
                                            {result.error}
                                        </div>
                                    )}

                                    {result.preview && (
                                        <div>
                                            <div className="mb-2 text-xs font-semibold text-muted-foreground">
                                                Preview
                                            </div>
                                            <pre className="max-h-64 overflow-auto rounded-md border bg-muted/40 p-3 text-xs">
                                                {result.preview}
                                            </pre>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {comparison && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Comparison</CardTitle>
                            <CardDescription>Quick parity checks between environments.</CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 text-sm sm:grid-cols-3">
                            <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2">
                                <span className="text-muted-foreground">Hash match</span>
                                <Badge variant={comparison.hash_match ? 'secondary' : 'destructive'}>
                                    {comparison.hash_match ? 'Match' : 'Mismatch'}
                                </Badge>
                            </div>
                            <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2">
                                <span className="text-muted-foreground">Size match</span>
                                <Badge variant={comparison.size_match ? 'secondary' : 'destructive'}>
                                    {comparison.size_match ? 'Match' : 'Mismatch'}
                                </Badge>
                            </div>
                            <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2">
                                <span className="text-muted-foreground">Duration diff</span>
                                <span>
                                    {comparison.duration_diff_ms !== null &&
                                    comparison.duration_diff_ms !== undefined
                                        ? `${comparison.duration_diff_ms} ms`
                                        : 'n/a'}
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
