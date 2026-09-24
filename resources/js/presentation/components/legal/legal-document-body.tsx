import { Text } from '@/presentation/components/typography';

interface LegalDocument {
    title: string;
    updated: string;
    sections: { heading: string; body: string }[];
}

export function LegalDocumentBody({ document }: { document: LegalDocument }) {
    return (
        <div className="space-y-4">
            <Text variant="caption">{document.updated}</Text>
            {document.sections.map((section) => (
                <div key={section.heading} className="space-y-2">
                    <Text as="h3" className="font-medium">
                        {section.heading}
                    </Text>
                    <Text variant="small">{section.body}</Text>
                </div>
            ))}
        </div>
    );
}
